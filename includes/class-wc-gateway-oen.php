<?php

defined( 'ABSPATH' ) || exit;

/**
 * Abstract base class for OEN payment gateways.
 *
 * Handles shared logic: reading settings, building checkout params,
 * calling the OEN API, and redirecting to the hosted checkout page.
 * Subclasses define payment_method_type and gateway-specific config.
 */
abstract class WC_Gateway_OEN extends WC_Payment_Gateway {

    /**
     * The OEN payment method type: 'card', 'cvs', or 'atm'.
     */
    protected string $payment_method_type;

    /**
     * Initialize shared gateway properties and form fields.
     */
    public function __construct() {
        // Subclass must set: $this->id, $this->method_title, $this->method_description,
        // $this->payment_method_type, $this->icon before calling parent constructor.

        $this->has_fields = false;
        $this->supports   = [ 'products' ];

        // Only card payments can be refunded through the Hosted Checkout refund API.
        // CVS/ATM refunds are asynchronous and the backend does not support them, so
        // those gateways must not advertise 'refunds' — WooCommerce would render a
        // refund button that can only fail.
        if ( 'card' === $this->payment_method_type ) {
            $this->supports[] = 'refunds';
        }

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option( 'title' );
        $this->description = $this->get_option( 'description' );
        $this->enabled     = $this->get_option( 'enabled' );

        add_action(
            'woocommerce_update_options_payment_gateways_' . $this->id,
            [ $this, 'process_admin_options' ]
        );
    }

    /**
     * Define per-gateway form fields (enable, title, description).
     */
    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled'     => [
                'title'   => __( 'Enable/Disable', 'woocommerce-oen-payment' ),
                'type'    => 'checkbox',
                'label'   => sprintf(
                    /* translators: %s: payment method title */
                    __( 'Enable %s', 'woocommerce-oen-payment' ),
                    $this->method_title
                ),
                'default' => 'no',
            ],
            'title'       => [
                'title'   => __( 'Title', 'woocommerce-oen-payment' ),
                'type'    => 'text',
                'default' => $this->method_title,
            ],
            'description' => [
                'title'   => __( 'Description', 'woocommerce-oen-payment' ),
                'type'    => 'textarea',
                'default' => $this->method_description,
            ],
        ];
    }

    /**
     * Check if the gateway is available for use.
     *
     * Requires the master OEN toggle to be enabled and MerchantID + Secret Key set.
     */
    public function is_available(): bool {
        if ( 'yes' !== get_option( 'oen_enabled', 'no' ) ) {
            return false;
        }

        if ( empty( get_option( 'oen_merchant_id', '' ) ) || empty( get_option( 'oen_api_token', '' ) ) ) {
            return false;
        }

        return parent::is_available();
    }

    /**
     * Process the payment: create OEN hosted checkout session and redirect.
     *
     * @param int $order_id WooCommerce order ID.
     * @return array{result: string, redirect: string}
     */
    public function process_payment( $order_id ): array {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            wc_add_notice(
                __( 'Order not found.', 'woocommerce-oen-payment' ),
                'error'
            );
            return [ 'result' => 'failure' ];
        }

        if ( ! $this->acquire_order_lock( $order_id ) ) {
            wc_add_notice(
                __( 'Another OEN checkout attempt is already being prepared for this order. Please wait a moment and try again.', 'woocommerce-oen-payment' ),
                'error'
            );
            return [ 'result' => 'failure' ];
        }

        try {
            $order = wc_get_order( $order_id );

            if ( ! $order ) {
                wc_add_notice(
                    __( 'Order not found.', 'woocommerce-oen-payment' ),
                    'error'
                );
                return [ 'result' => 'failure' ];
            }

            if ( $order->is_paid() ) {
                return [
                    'result'   => 'success',
                    'redirect' => $this->get_return_url( $order ),
                ];
            }

            $client = OEN_API_Client::from_settings();

            $reusable_checkout_url = $this->get_reusable_checkout_url( $order, $client );

            if ( '' !== $reusable_checkout_url ) {
                return [
                    'result'   => 'success',
                    'redirect' => $reusable_checkout_url,
                ];
            }

            $params = $this->build_checkout_params( $order );
            $result = $client->create_session( $params );
            $session_id = sanitize_text_field( (string) ( $result['id'] ?? '' ) );
            $checkout_url = sanitize_text_field( (string) ( $result['checkoutUrl'] ?? '' ) );

            if ( '' === $session_id ) {
                throw new \RuntimeException(
                    __( 'OEN Payment API did not return a session id.', 'woocommerce-oen-payment' )
                );
            }

            if ( '' === $checkout_url ) {
                throw new \RuntimeException(
                    __( 'OEN Payment API did not return a checkout URL.', 'woocommerce-oen-payment' )
                );
            }

            // Store OEN session and transaction references as order meta.
            $oen_order_id = $params['orderId'];
            $order->update_meta_data( '_oen_order_id', $oen_order_id );
            $order->update_meta_data( '_oen_session_id', $session_id );
            $order->update_meta_data( '_oen_checkout_url', $checkout_url );
            if ( ! empty( $result['transactionId'] ) ) {
                $order->update_meta_data( '_oen_transaction_id', $result['transactionId'] );
            } else {
                $order->delete_meta_data( '_oen_transaction_id' );
            }
            if ( ! empty( $result['transactionHid'] ) ) {
                $order->update_meta_data( '_oen_transaction_hid', $result['transactionHid'] );
            } else {
                $order->delete_meta_data( '_oen_transaction_hid' );
            }
            $order->update_meta_data( '_oen_payment_method', $this->payment_method_type );
            $order->save();

            // CVS/ATM 需要等待客戶繳費，設為 on-hold 避免被 WooCommerce 自動取消。
            if ( in_array( $this->payment_method_type, [ 'cvs', 'atm' ], true ) ) {
                $order->update_status(
                    'on-hold',
                    __( 'Awaiting OEN off-site payment.', 'woocommerce-oen-payment' )
                );
            }

            return [
                'result'   => 'success',
                'redirect' => $checkout_url,
            ];
        } catch ( \RuntimeException $e ) {
            // Never surface internal API error details to customers — log them and
            // show a generic notice instead.
            wc_get_logger()->error(
                sprintf( 'OEN checkout failed for order #%d: %s', $order_id, $e->getMessage() ),
                [ 'source' => 'oen-payment' ]
            );
            wc_add_notice(
                __( 'Payment processing failed. Please try again or contact support.', 'woocommerce-oen-payment' ),
                'error'
            );
            return [ 'result' => 'failure' ];
        } finally {
            $this->release_order_lock( $order_id );
        }
    }

    /**
     * Refund a paid order through the Hosted Checkout refund API.
     *
     * WooCommerce creates the WC_Order_Refund itself, but only when this method
     * returns true — so every failure path must return WP_Error. Returning true
     * (or false) after a failed API call would leave the order marked refunded
     * while the money was never returned.
     *
     * @param int        $order_id Order ID.
     * @param float|null $amount   Refund amount.
     * @param string     $reason   Refund reason.
     * @return bool|\WP_Error True on success, WP_Error otherwise.
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $order = wc_get_order( $order_id );

        if ( ! $order instanceof \WC_Order ) {
            return new \WP_Error(
                'oen_refund_order_not_found',
                __( 'Order not found.', 'woocommerce-oen-payment' )
            );
        }

        if ( 'card' !== $this->payment_method_type ) {
            return new \WP_Error(
                'oen_refund_unsupported_method',
                __( 'OEN only supports refunds for credit card payments.', 'woocommerce-oen-payment' )
            );
        }

        $session_id = sanitize_text_field( (string) $order->get_meta( '_oen_session_id' ) );

        if ( '' === $session_id ) {
            return new \WP_Error(
                'oen_refund_missing_session',
                __( 'This order has no OEN session id, so it cannot be refunded through OEN.', 'woocommerce-oen-payment' )
            );
        }

        $refund_amount = (int) round( (float) $amount );

        if ( $refund_amount <= 0 ) {
            return new \WP_Error(
                'oen_refund_invalid_amount',
                __( 'Refund amount must be greater than zero.', 'woocommerce-oen-payment' )
            );
        }

        // Claim the refund before the request goes out. The backend emits
        // refund.succeeded while it is still answering this call, so the webhook can
        // land before the refund id is known here.
        OEN_Refund_Registry::begin( $order );

        try {
            $refund = OEN_API_Client::from_settings()->create_refund(
                $session_id,
                $refund_amount,
                (string) $reason
            );
        } catch ( \RuntimeException $e ) {
            OEN_Refund_Registry::end( $order );

            wc_get_logger()->error(
                sprintf( 'OEN refund failed for order #%d: %s', $order->get_id(), $e->getMessage() ),
                [ 'source' => 'oen-payment' ]
            );

            return new \WP_Error( 'oen_refund_failed', $e->getMessage() );
        }

        $status = sanitize_text_field( (string) ( $refund['status'] ?? '' ) );

        if ( 'refunded' !== $status ) {
            OEN_Refund_Registry::end( $order );

            wc_get_logger()->error(
                sprintf(
                    'OEN refund for order #%1$d returned non-terminal status: %2$s',
                    $order->get_id(),
                    $status ?: 'unknown'
                ),
                [ 'source' => 'oen-payment' ]
            );

            return new \WP_Error(
                'oen_refund_not_terminal',
                __( 'OEN did not confirm the refund. The order was not marked as refunded.', 'woocommerce-oen-payment' )
            );
        }

        $refund_id = sanitize_text_field( (string) ( $refund['id'] ?? '' ) );

        // Claim the refund id before returning true. The refund.succeeded webhook
        // arrives for this same refund and would otherwise mirror it a second time,
        // doubling total_refunded on the order.
        OEN_Refund_Registry::mark_processed( $order, $refund_id );

        $order->add_order_note(
            sprintf(
                /* translators: 1: refund amount, 2: OEN refund id */
                __( 'Refunded %1$d via OEN (refund %2$s).', 'woocommerce-oen-payment' ),
                $refund_amount,
                $refund_id ?: 'unknown'
            )
        );

        return true;
    }

    /**
     * Reuse the current hosted checkout attempt when the order already has an
     * active session and its checkout URL is still usable.
     *
     * @param \WC_Order       $order  WooCommerce order.
     * @param OEN_API_Client  $client API client.
     * @return string Reusable checkout URL, or empty string when a fresh attempt is needed.
     * @throws \RuntimeException When the stored session cannot be verified safely.
     */
    protected function get_reusable_checkout_url( \WC_Order $order, OEN_API_Client $client ): string {
        $session_id = sanitize_text_field( (string) $order->get_meta( '_oen_session_id' ) );

        if ( '' === $session_id ) {
            return '';
        }

        try {
            $session = $client->get_session( $session_id );
        } catch ( \Throwable $exception ) {
            throw new \RuntimeException(
                __( 'We could not verify your existing OEN checkout session. Please try again in a moment.', 'woocommerce-oen-payment' ),
                0,
                $exception
            );
        }

        $session_state = self::classify_reusable_session_response( $session );

        if ( 'unsafe' === $session_state ) {
            throw $this->get_reusable_session_verification_exception();
        }

        $this->assert_verified_session_matches_order( $order, $session_id, $session );

        if ( 'refreshable_terminal' === $session_state ) {
            return '';
        }

        if ( 'verified_success_terminal' === $session_state ) {
            throw $this->get_reusable_session_verification_exception();
        }

        $checkout_url = sanitize_text_field( (string) ( $session['checkoutUrl'] ?? '' ) );
        if ( '' !== $checkout_url ) {
            if ( $checkout_url !== sanitize_text_field( (string) $order->get_meta( '_oen_checkout_url' ) ) ) {
                $order->update_meta_data( '_oen_checkout_url', $checkout_url );
                $order->save();
            }

            return $checkout_url;
        }

        $checkout_url = sanitize_text_field( (string) $order->get_meta( '_oen_checkout_url' ) );

        if ( '' === $checkout_url ) {
            throw new \RuntimeException(
                __( 'Your existing OEN checkout session is still active, but its checkout URL is unavailable. Please try again in a moment.', 'woocommerce-oen-payment' )
            );
        }

        return $checkout_url;
    }

    /**
     * Verify that a fetched Hosted Checkout session is still bound to the current order.
     *
     * @param \WC_Order             $order      WooCommerce order.
     * @param string                $session_id Stored Hosted Checkout session id.
     * @param array<string, mixed>  $session    Hosted Checkout session payload.
     *
     * @throws \RuntimeException When the stored session cannot be safely bound to the order.
     */
    protected function assert_verified_session_matches_order( \WC_Order $order, string $session_id, array $session ): void {
        $response_session_id = sanitize_text_field( (string) ( $session['id'] ?? $session['sessionId'] ?? '' ) );
        if ( '' === $response_session_id || $response_session_id !== $session_id ) {
            throw $this->get_reusable_session_verification_exception();
        }

        $expected_order_id = sanitize_text_field(
            (string) ( $this->build_checkout_params( $order )['orderId'] ?? '' )
        );
        $session_order_id  = sanitize_text_field( (string) ( $session['orderId'] ?? '' ) );

        if ( '' === $expected_order_id || '' === $session_order_id || $session_order_id !== $expected_order_id ) {
            throw $this->get_reusable_session_verification_exception();
        }

        if ( ! array_key_exists( 'amount', $session ) || '' === sanitize_text_field( (string) $session['amount'] ) ) {
            throw $this->get_reusable_session_verification_exception();
        }

        $session_amount = intval( $session['amount'] );
        if ( $session_amount !== intval( $order->get_total() ) ) {
            throw $this->get_reusable_session_verification_exception();
        }
    }

    /**
     * Treat non-terminal hosted checkout session states as reusable.
     *
     * @param array<string, mixed> $session Hosted checkout session payload.
     */
    protected static function is_reusable_session_response( array $session ): bool {
        return 'reusable' === self::classify_reusable_session_response( $session );
    }

    /**
     * Classify whether a fetched Hosted Checkout session is safely reusable.
     *
     * @return 'reusable'|'refreshable_terminal'|'verified_success_terminal'|'unsafe'
     */
    protected static function classify_reusable_session_response( array $session ): string {
        $status = self::normalize_session_status( $session );

        if ( '' === $status ) {
            return 'unsafe';
        }

        if ( in_array( $status, [ 'failed', 'expired', 'cancelled' ], true ) ) {
            return 'refreshable_terminal';
        }

        if ( in_array( $status, [ 'completed', 'charged' ], true ) ) {
            return 'verified_success_terminal';
        }

        return 'reusable';
    }

    /**
     * Normalize verified Hosted Checkout session status.
     *
     * The Hosted Checkout session API returns the lifecycle status at the top
     * level; a nested `transaction.status` is accepted only as a forward-compat
     * fallback. Delegates to the webhook handler when available so both the
     * verification and reuse paths share a single source of truth.
     *
     * @param array<string, mixed> $session Hosted checkout session payload.
     */
    protected static function normalize_session_status( array $session ): string {
        if ( class_exists( 'OEN_Webhook_Handler' ) && method_exists( 'OEN_Webhook_Handler', 'normalize_verified_session_status' ) ) {
            return OEN_Webhook_Handler::normalize_verified_session_status( $session );
        }

        $status = sanitize_text_field( (string) ( $session['status'] ?? '' ) );

        if ( '' !== $status ) {
            return $status;
        }

        $transaction = is_array( $session['transaction'] ?? null ) ? $session['transaction'] : [];

        return sanitize_text_field( (string) ( $transaction['status'] ?? '' ) );
    }

    /**
     * Build the fail-closed exception used when a reusable session cannot be safely verified.
     */
    protected function get_reusable_session_verification_exception(): \RuntimeException {
        return new \RuntimeException(
            __( 'We could not safely verify your existing OEN checkout session. Please try again in a moment.', 'woocommerce-oen-payment' )
        );
    }

    /**
     * Acquire a per-order advisory lock before deciding whether to reuse or create a session.
     *
     * @param int $order_id WooCommerce order ID.
     */
    protected function acquire_order_lock( int $order_id ): bool {
        global $wpdb;

        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
            return true;
        }

        // Wait briefly so duplicate clicks can reuse the first attempt instead of failing open.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $result = $wpdb->get_var(
            $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $this->get_order_lock_name( $order_id ) )
        );

        return '1' === (string) $result;
    }

    /**
     * Release the per-order advisory lock.
     *
     * @param int $order_id WooCommerce order ID.
     */
    protected function release_order_lock( int $order_id ): void {
        global $wpdb;

        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'query' ) ) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query(
            $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->get_order_lock_name( $order_id ) )
        );
    }

    /**
     * Build the advisory lock name for an order-scoped Hosted Checkout attempt.
     *
     * @param int $order_id WooCommerce order ID.
     */
    protected function get_order_lock_name( int $order_id ): string {
        global $wpdb;

        $prefix = '';
        if ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->prefix ) && is_string( $wpdb->prefix ) ) {
            $prefix = $wpdb->prefix;
        }

        return $prefix . 'oen_order_' . $order_id;
    }

    /**
     * Build the OEN POST /checkout request parameters from a WC order.
     *
     * @param \WC_Order $order The WooCommerce order.
     * @return array Checkout API request body.
     */
    protected function build_checkout_params( \WC_Order $order ): array {
        $prefix   = get_option( 'oen_order_prefix', '' );
        $order_id = $prefix . $order->get_id();

        $params = [
            'amount'         => intval( $order->get_total() ),
            'currency'       => 'TWD',
            'orderId'        => $order_id,
            'successUrl'     => $this->get_return_url( $order ),
            'failureUrl'     => wc_get_checkout_url(),
            'cancelUrl'      => wc_get_cart_url(),
            'userName'       => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            'userEmail'      => $order->get_billing_email(),
            'productDetails' => $this->build_product_details( $order ),
        ];

        // Subclasses may add allowedPaymentMethods.
        $allowed = $this->get_allowed_payment_methods();
        if ( ! empty( $allowed ) ) {
            $params['allowedPaymentMethods'] = $allowed;
        }

        return $params;
    }

    /**
     * Build the productDetails array from WC order items.
     *
     * @param \WC_Order $order The WooCommerce order.
     * @return array<array{productionCode: string, description: string, quantity: int, unit: string, unitPrice: int}>
     */
    protected function build_product_details( \WC_Order $order ): array {
        $display_item_name = 'yes' === get_option( 'oen_display_item_name', 'no' );

        if ( ! $display_item_name ) {
            return [
                [
                    'productionCode' => 'ORDER',
                    'description'    => sprintf(
                        /* translators: %s: site name */
                        __( '%s Order', 'woocommerce-oen-payment' ),
                        get_bloginfo( 'name' )
                    ),
                    'quantity'       => 1,
                    'unit'           => __( 'set', 'woocommerce-oen-payment' ),
                    'unitPrice'      => intval( $order->get_total() ),
                ],
            ];
        }

        $details = [];

        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            $sku     = $product ? $product->get_sku() : '';

            $details[] = [
                'productionCode' => $sku ?: (string) $item->get_product_id(),
                'description'    => $item->get_name(),
                'quantity'       => $item->get_quantity(),
                'unit'           => __( 'pc', 'woocommerce-oen-payment' ),
                'unitPrice'      => (int) round( (float) $order->get_item_total( $item, true ) ),
            ];
        }

        // Include fees (e.g. surcharges) as line items.
        foreach ( $order->get_fees() as $fee ) {
            $fee_total = (int) round( (float) $fee->get_total() + (float) $fee->get_total_tax() );
            if ( 0 !== $fee_total ) {
                $details[] = [
                    'productionCode' => 'FEE',
                    'description'    => $fee->get_name(),
                    'quantity'       => 1,
                    'unit'           => __( 'set', 'woocommerce-oen-payment' ),
                    'unitPrice'      => $fee_total,
                ];
            }
        }

        // Include shipping as a line item if > 0.
        $shipping_total = (int) round( (float) $order->get_shipping_total() + (float) $order->get_shipping_tax() );
        if ( $shipping_total > 0 ) {
            $details[] = [
                'productionCode' => 'SHIPPING',
                'description'    => __( 'Shipping', 'woocommerce-oen-payment' ),
                'quantity'       => 1,
                'unit'           => __( 'set', 'woocommerce-oen-payment' ),
                'unitPrice'      => $shipping_total,
            ];
        }

        // Adjustment line item to ensure sum(unitPrice * quantity) === order total,
        // so the backend's productDetails amount check cannot reject the session.
        $sum         = array_sum( array_map( fn( $d ) => $d['unitPrice'] * $d['quantity'], $details ) );
        $order_total = (int) round( (float) $order->get_total() );
        $diff        = $order_total - $sum;

        if ( 0 !== $diff ) {
            $details[] = [
                'productionCode' => 'ADJ',
                'description'    => __( 'Order adjustment', 'woocommerce-oen-payment' ),
                'quantity'       => 1,
                'unit'           => __( 'set', 'woocommerce-oen-payment' ),
                'unitPrice'      => $diff,
            ];
        }

        return $details;
    }

    /**
     * Get the allowed payment methods for this gateway.
     * Override in subclasses that restrict to a specific method.
     *
     * @return string[] Empty array means OEN default (credit card).
     */
    protected function get_allowed_payment_methods(): array {
        return [];
    }
}
