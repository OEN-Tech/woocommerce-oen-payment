<?php

defined( 'ABSPATH' ) || exit;

class OEN_Settings extends WC_Settings_Page {

    public function __construct() {
        $this->id    = 'oen_payment';
        $this->label = __( 'OEN', 'woocommerce-oen-payment' );
        parent::__construct();
    }

    public function get_settings_for_default_section(): array {
        return [
            [
                'title' => __( 'Enable OEN method', 'woocommerce-oen-payment' ),
                'type'  => 'title',
                'id'    => 'oen_enable_section',
            ],
            [
                'title'   => __( 'Enable OEN gateway method', 'woocommerce-oen-payment' ),
                'desc'    => __( 'Enable gateway method', 'woocommerce-oen-payment' ),
                'id'      => 'oen_enabled',
                'type'    => 'checkbox',
                'default' => 'no',
            ],
            [
                'type' => 'sectionend',
                'id'   => 'oen_enable_section',
            ],
            [
                'title' => __( 'Gateway settings', 'woocommerce-oen-payment' ),
                'type'  => 'title',
                'id'    => 'oen_gateway_section',
            ],
            [
                'title'    => __( 'Order no prefix', 'woocommerce-oen-payment' ),
                'desc_tip' => __( 'Prefix prepended to the WooCommerce order ID when sent to OEN.', 'woocommerce-oen-payment' ),
                'id'       => 'oen_order_prefix',
                'type'     => 'text',
                'default'  => '',
            ],
            [
                'title'   => __( 'Display order item name', 'woocommerce-oen-payment' ),
                'desc'    => __( 'Display order item name', 'woocommerce-oen-payment' ),
                'id'      => 'oen_display_item_name',
                'type'    => 'checkbox',
                'default' => 'no',
            ],
            [
                'title'   => __( 'Show payment info in email', 'woocommerce-oen-payment' ),
                'desc'    => __( 'Enabled payment shop email', 'woocommerce-oen-payment' ),
                'id'      => 'oen_show_payment_in_email',
                'type'    => 'checkbox',
                'default' => 'no',
            ],
            [
                'type' => 'sectionend',
                'id'   => 'oen_gateway_section',
            ],
            [
                'title' => __( 'API settings', 'woocommerce-oen-payment' ),
                'type'  => 'title',
                'id'    => 'oen_api_section',
            ],
            [
                'title'   => __( 'OEN sandbox', 'woocommerce-oen-payment' ),
                'desc'    => __( 'sandbox', 'woocommerce-oen-payment' ),
                'id'      => 'oen_sandbox',
                'type'    => 'checkbox',
                'default' => 'no',
            ],
            [
                'title'   => __( 'MerchantID', 'woocommerce-oen-payment' ),
                'id'      => 'oen_merchant_id',
                'type'    => 'text',
                'default' => '',
            ],
            [
                'title'   => __( 'API Token', 'woocommerce-oen-payment' ),
                'id'      => 'oen_api_token',
                'type'    => 'password',
                'default' => '',
            ],
            [
                'title'    => __( 'Webhook Secret', 'woocommerce-oen-payment' ),
                'desc_tip' => __( 'HMAC secret for webhook signature verification (X-OEN-Signature header). Leave empty to skip signature check.', 'woocommerce-oen-payment' ),
                'id'       => 'oen_webhook_secret',
                'type'     => 'password',
                'default'  => '',
            ],
            [
                'type' => 'sectionend',
                'id'   => 'oen_api_section',
            ],
        ];
    }
}
