<?php

defined( 'ABSPATH' ) || exit;

/**
 * Storage and presentation of OEN off-site payment information (currently CVS codes).
 *
 * A CVS buyer is redirected to the OEN hosted checkout, chooses convenience-store
 * payment there, and is shown a payment code — on OEN's page, not the store's. The
 * buyer never returns to WooCommerce, so unless the store fetches the code itself it
 * has no record of it: the order sits on-hold with empty _oen_cvs_* meta and the code
 * exists nowhere the merchant or the buyer can look it up again.
 *
 * This class owns the meta keys and the two surfaces that display them. The fetching
 * is in OEN_Payment_Info_Sync.
 */
class OEN_Payment_Info {

    public const META_CODE    = '_oen_cvs_code';
    public const META_NAME    = '_oen_cvs_name';
    public const META_EXPIRES = '_oen_cvs_expired_at';

    /**
     * Payment methods that can produce an off-site payment code.
     */
    public const CODE_METHODS = [ 'oen_cvs', 'oen_atm' ];

    public function __construct() {
        add_action( 'woocommerce_order_details_after_order_table', [ $this, 'render_for_customer' ], 10, 1 );
        add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'render_for_admin' ], 10, 1 );
    }

    /**
     * Write a paymentInfo payload onto the order. Returns true when something changed.
     *
     * Accepts the shape returned by both GET /sessions/{id} (top-level paymentInfo)
     * and the transaction payload carried by webhooks.
     *
     * @param \WC_Order            $order        The WooCommerce order.
     * @param array<string, mixed> $payment_info paymentInfo payload.
     */
    public static function apply( \WC_Order $order, array $payment_info ): bool {
        $map = [
            self::META_NAME    => 'cvsName',
            self::META_CODE    => 'code',
            self::META_EXPIRES => 'expiredAt',
        ];

        $changed = false;

        foreach ( $map as $meta_key => $payload_key ) {
            $value = $payment_info[ $payload_key ] ?? '';

            if ( ! is_scalar( $value ) || '' === (string) $value ) {
                continue;
            }

            $value = sanitize_text_field( (string) $value );

            if ( $value === (string) $order->get_meta( $meta_key ) ) {
                continue;
            }

            $order->update_meta_data( $meta_key, $value );
            $changed = true;
        }

        return $changed;
    }

    /**
     * The stored payment info, or an empty array when there is none.
     *
     * @param \WC_Order $order The WooCommerce order.
     * @return array{code: string, name: string, expires: string}|array{}
     */
    public static function get( \WC_Order $order ): array {
        $code = (string) $order->get_meta( self::META_CODE );

        if ( '' === $code ) {
            return [];
        }

        return [
            'code'    => $code,
            'name'    => (string) $order->get_meta( self::META_NAME ),
            'expires' => (string) $order->get_meta( self::META_EXPIRES ),
        ];
    }

    /**
     * Whether this order should have a payment code but does not have one yet.
     *
     * @param \WC_Order $order The WooCommerce order.
     */
    public static function is_pending( \WC_Order $order ): bool {
        if ( ! in_array( $order->get_payment_method(), self::CODE_METHODS, true ) ) {
            return false;
        }

        if ( '' !== (string) $order->get_meta( self::META_CODE ) ) {
            return false;
        }

        return in_array( $order->get_status(), [ 'on-hold', 'pending' ], true );
    }

    /**
     * Customer-facing: order-received page and My account → order.
     *
     * @param \WC_Order $order The WooCommerce order.
     */
    public function render_for_customer( $order ): void {
        if ( ! $order instanceof \WC_Order ) {
            return;
        }

        $info = self::get( $order );

        if ( [] === $info ) {
            return;
        }

        echo '<h2 class="woocommerce-oen-payment-info__title">'
            . esc_html__( 'Payment information', 'woocommerce-oen-payment' ) . '</h2>';
        echo '<table class="woocommerce-table shop_table woocommerce-oen-payment-info"><tbody>';

        if ( '' !== $info['name'] ) {
            echo '<tr><th>' . esc_html__( 'Convenience store', 'woocommerce-oen-payment' ) . '</th>';
            echo '<td>' . esc_html( $info['name'] ) . '</td></tr>';
        }

        echo '<tr><th>' . esc_html__( 'Payment code', 'woocommerce-oen-payment' ) . '</th>';
        echo '<td><strong>' . esc_html( $info['code'] ) . '</strong></td></tr>';

        if ( '' !== $info['expires'] ) {
            echo '<tr><th>' . esc_html__( 'Pay before', 'woocommerce-oen-payment' ) . '</th>';
            echo '<td>' . esc_html( $info['expires'] ) . '</td></tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Admin order screen.
     *
     * @param \WC_Order $order The WooCommerce order.
     */
    public function render_for_admin( $order ): void {
        if ( ! $order instanceof \WC_Order ) {
            return;
        }

        $info = self::get( $order );

        if ( [] === $info ) {
            return;
        }

        echo '<div class="address"><p><strong>'
            . esc_html__( 'OEN payment code', 'woocommerce-oen-payment' ) . ':</strong> '
            . esc_html( $info['code'] );

        if ( '' !== $info['name'] ) {
            echo ' (' . esc_html( $info['name'] ) . ')';
        }

        if ( '' !== $info['expires'] ) {
            echo '<br><small>' . esc_html__( 'Pay before', 'woocommerce-oen-payment' ) . ': '
                . esc_html( $info['expires'] ) . '</small>';
        }

        echo '</p></div>';
    }
}
