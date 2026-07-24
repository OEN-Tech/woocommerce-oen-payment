<?php

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class OEN_Blocks_Payment_Method extends AbstractPaymentMethodType {

    private string $gateway_class;

    public function __construct( string $name, string $gateway_class ) {
        $this->name          = $name;
        $this->gateway_class = $gateway_class;
    }

    public function initialize(): void {
        $this->settings = get_option( 'woocommerce_' . $this->name . '_settings', [] );
    }

    public function is_active(): bool {
        if ( 'yes' !== get_option( 'oen_enabled', 'no' ) ) {
            return false;
        }

        if ( empty( get_option( 'oen_merchant_id', '' ) ) || empty( get_option( 'oen_api_token', '' ) ) ) {
            return false;
        }

        if ( 'yes' !== $this->get_setting( 'enabled', 'no' ) ) {
            return false;
        }

        return class_exists( $this->gateway_class );
    }

    public function get_payment_method_script_handles(): array {
        $handle = 'oen-blocks-payment-method';

        wp_register_script(
            $handle,
            OEN_PAYMENT_PLUGIN_URL . 'assets/js/blocks-payment-method.js',
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wc-sanitize',
            ],
            OEN_PAYMENT_VERSION,
            true
        );

        return [ $handle ];
    }

    public function get_payment_method_script_handles_for_admin(): array {
        // Reuse the same registration so the method previews in the block editor.
        return $this->get_payment_method_script_handles();
    }

    public function get_payment_method_data(): array {
        return [
            'title'       => $this->get_setting( 'title' ),
            'description' => $this->get_setting( 'description' ),
            'supports'    => $this->get_supported_features(),
        ];
    }
}
