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

        try {
            $client = OEN_API_Client::from_settings();
            $params = $this->build_checkout_params( $order );
            $result = $client->create_session( $params );
            $session_id = sanitize_text_field( (string) ( $result['id'] ?? '' ) );

            if ( '' === $session_id ) {
                throw new \RuntimeException(
                    __( 'OEN Payment API did not return a session id.', 'woocommerce-oen-payment' )
                );
            }

            // Store OEN session and transaction references as order meta.
            $oen_order_id = $params['orderId'];
            $order->update_meta_data( '_oen_order_id', $oen_order_id );
            $order->update_meta_data( '_oen_session_id', $session_id );
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

            $checkout_url = $result['checkoutUrl'] ?? '';

            if ( empty( $checkout_url ) ) {
                throw new \RuntimeException(
                    __( 'OEN Payment API did not return a checkout URL.', 'woocommerce-oen-payment' )
                );
            }

            return [
                'result'   => 'success',
                'redirect' => $checkout_url,
            ];
        } catch ( \RuntimeException $e ) {
            wc_add_notice( $e->getMessage(), 'error' );
            return [ 'result' => 'failure' ];
        }
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
