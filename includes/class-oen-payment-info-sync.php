<?php

defined( 'ABSPATH' ) || exit;

/**
 * Fetches the off-site payment code for orders that are waiting for one.
 *
 * The code is created when the buyer picks convenience-store payment on the OEN
 * hosted checkout page, which happens AFTER the redirect and on OEN's domain. The
 * store therefore never sees it in-request: at process_payment() time the code does
 * not exist yet, and the buyer does not come back.
 *
 * There is also no webhook for it — the Hosted Checkout API emits
 * checkout_session.completed/failed/expired/cancelled and refund.*, none of which
 * fire when a code is issued. GET /hosted-checkout/v1/sessions/{id} does return a
 * top-level paymentInfo once the code exists, so this class polls that endpoint a
 * bounded number of times after the order goes on-hold.
 *
 * Polling is a deliberate second choice. If the backend later emits an event when
 * the code is created, the handler can call OEN_Payment_Info::apply() directly and
 * this scheduler can be deleted; nothing else needs to change.
 */
class OEN_Payment_Info_Sync {

    public const HOOK = 'oen_payment_info_sync';

    /**
     * Delay before each attempt, in seconds. The last attempt lands after the
     * session TTL (~30 min), by which point the code either exists or never will.
     *
     * @var array<int, int>
     */
    private const SCHEDULE = [ 60, 180, 600, 1800 ];

    public function __construct() {
        add_action( 'woocommerce_order_status_on-hold', [ $this, 'schedule_first_attempt' ], 10, 2 );
        add_action( self::HOOK, [ $this, 'run' ], 10, 2 );
    }

    /**
     * Queue the first fetch when an OEN off-site order starts waiting for payment.
     *
     * @param int            $order_id The order id.
     * @param \WC_Order|null $order    The order, as passed by WooCommerce.
     */
    public function schedule_first_attempt( $order_id, $order = null ): void {
        $order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );

        if ( ! $order instanceof \WC_Order || ! OEN_Payment_Info::is_pending( $order ) ) {
            return;
        }

        self::schedule( (int) $order_id, 0 );
    }

    /**
     * One fetch attempt.
     *
     * @param int $order_id The order id.
     * @param int $attempt  Zero-based attempt number.
     */
    public function run( $order_id, $attempt = 0 ): void {
        $order_id = (int) $order_id;
        $attempt  = (int) $attempt;
        $order    = wc_get_order( $order_id );

        if ( ! $order instanceof \WC_Order || ! OEN_Payment_Info::is_pending( $order ) ) {
            return;
        }

        $session_id = sanitize_text_field( (string) $order->get_meta( '_oen_session_id' ) );

        if ( '' === $session_id ) {
            return;
        }

        try {
            $session = OEN_API_Client::from_settings()->get_session( $session_id );
        } catch ( \Throwable $e ) {
            $this->log(
                sprintf( 'payment info fetch failed for order #%1$d: %2$s', $order_id, $e->getMessage() )
            );
            self::schedule( $order_id, $attempt + 1 );
            return;
        }

        $payment_info = is_array( $session['paymentInfo'] ?? null ) ? $session['paymentInfo'] : [];

        if ( [] === $payment_info ) {
            // The buyer has not chosen a payment method on the hosted page yet, or the
            // session reached a state that will never produce a code.
            if ( in_array( (string) ( $session['status'] ?? '' ), [ 'expired', 'cancelled', 'failed' ], true ) ) {
                return;
            }

            self::schedule( $order_id, $attempt + 1 );
            return;
        }

        if ( ! OEN_Payment_Info::apply( $order, $payment_info ) ) {
            return;
        }

        $order->add_order_note(
            sprintf(
                /* translators: %s: convenience store payment code */
                __( 'OEN payment code received: %s', 'woocommerce-oen-payment' ),
                (string) ( $payment_info['code'] ?? '' )
            )
        );
        $order->save();

        $this->log( sprintf( 'payment info stored for order #%d', $order_id ) );
    }

    /**
     * Queue an attempt, unless the schedule is exhausted.
     *
     * @param int $order_id The order id.
     * @param int $attempt  Zero-based attempt number.
     */
    private static function schedule( int $order_id, int $attempt ): void {
        if ( ! isset( self::SCHEDULE[ $attempt ] ) ) {
            return;
        }

        $timestamp = time() + self::SCHEDULE[ $attempt ];
        $args      = [ $order_id, $attempt ];

        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action( $timestamp, self::HOOK, $args, 'oen-payment' );
            return;
        }

        wp_schedule_single_event( $timestamp, self::HOOK, $args );
    }

    private function log( string $message ): void {
        wc_get_logger()->info( $message, [ 'source' => 'oen-payment-info' ] );
    }
}
