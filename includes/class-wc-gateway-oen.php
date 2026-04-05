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

            return [
                'result'   => 'success',
                'redirect' => $checkout_url,
            ];
        } catch ( \RuntimeException $e ) {
            wc_add_notice( $e->getMessage(), 'error' );
            return [ 'result' => 'failure' ];
        } finally {
            $this->release_order_lock( $order_id );
        }
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

        if ( ! self::is_reusable_session_response( $session ) ) {
            return '';
        }

        $checkout_url = sanitize_text_field( (string) $order->get_meta( '_oen_checkout_url' ) );

        if ( '' === $checkout_url ) {
            $checkout_url = sanitize_text_field( (string) ( $session['checkoutUrl'] ?? '' ) );

            if ( '' !== $checkout_url ) {
                $order->update_meta_data( '_oen_checkout_url', $checkout_url );
                $order->save();
            }
        }

        if ( '' === $checkout_url ) {
            throw new \RuntimeException(
                __( 'Your existing OEN checkout session is still active, but its checkout URL is unavailable. Please try again in a moment.', 'woocommerce-oen-payment' )
            );
        }

        return $checkout_url;
    }

    /**
     * Treat non-terminal hosted checkout session states as reusable.
     *
     * @param array<string, mixed> $session Hosted checkout session payload.
     */
    protected static function is_reusable_session_response( array $session ): bool {
        $status = self::normalize_session_status( $session );

        if ( '' === $status ) {
            return false;
        }

        return ! in_array(
            $status,
            [
                'completed',
                'charged',
                'failed',
                'expired',
                'cancelled',
            ],
            true
        );
    }

    /**
     * Normalize verified Hosted Checkout session status.
     *
     * Prefer the nested transaction status when present because it reflects the
     * authoritative payment outcome returned by the session verification API.
     *
     * @param array<string, mixed> $session Hosted checkout session payload.
     */
    protected static function normalize_session_status( array $session ): string {
        if ( class_exists( 'OEN_Webhook_Handler' ) && method_exists( 'OEN_Webhook_Handler', 'normalize_verified_session_status' ) ) {
            return OEN_Webhook_Handler::normalize_verified_session_status( $session );
        }

        $transaction = is_array( $session['transaction'] ?? null ) ? $session['transaction'] : [];
        $status      = sanitize_text_field( (string) ( $transaction['status'] ?? '' ) );

        if ( '' !== $status ) {
            return $status;
        }

        return sanitize_text_field( (string) ( $session['status'] ?? '' ) );
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
                'unitPrice'      => intval( $order->get_item_total( $item, false ) ),
            ];
        }

        // Include shipping as a line item if > 0.
        $shipping_total = intval( $order->get_shipping_total() );
        if ( $shipping_total > 0 ) {
            $details[] = [
                'productionCode' => 'SHIPPING',
                'description'    => __( 'Shipping', 'woocommerce-oen-payment' ),
                'quantity'       => 1,
                'unit'           => __( 'set', 'woocommerce-oen-payment' ),
                'unitPrice'      => $shipping_total,
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
