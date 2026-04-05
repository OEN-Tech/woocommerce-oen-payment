<?php
/**
 * Plugin Name: WooCommerce OEN Payment Gateway
 * Plugin URI: https://oen.tw
 * Description: OEN 金流付款外掛 — 支援信用卡、超商繳費付款方式
 * Version: 1.0.0
 * Author: OEN Technology (應援科技)
 * Author URI: https://oen.tw
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: woocommerce-oen-payment
 * Domain Path: /languages
 * Requires at least: 6.1
 * Requires PHP: 8.1
 * WC requires at least: 8.2
 * WC tested up to: 9.6
 */

defined( 'ABSPATH' ) || exit;

define( 'OEN_PAYMENT_VERSION', '1.0.0' );
define( 'OEN_PAYMENT_PLUGIN_FILE', __FILE__ );
define( 'OEN_PAYMENT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OEN_PAYMENT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

function oen_payment_check_woocommerce(): bool {
    return class_exists( 'WooCommerce' );
}

function oen_payment_missing_wc_notice(): void {
    echo '<div class="error"><p>';
    echo esc_html__(
        'WooCommerce OEN Payment Gateway requires WooCommerce to be installed and active.',
        'woocommerce-oen-payment'
    );
    echo '</p></div>';
}

add_action( 'before_woocommerce_init', function (): void {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
} );

add_action( 'init', function (): void {
    load_plugin_textdomain(
        'woocommerce-oen-payment',
        false,
        dirname( plugin_basename( __FILE__ ) ) . '/languages'
    );
} );

add_action( 'plugins_loaded', function (): void {
    if ( ! oen_payment_check_woocommerce() ) {
        add_action( 'admin_notices', 'oen_payment_missing_wc_notice' );
        return;
    }

    require_once OEN_PAYMENT_PLUGIN_DIR . 'includes/class-oen-api-client.php';
    require_once OEN_PAYMENT_PLUGIN_DIR . 'includes/class-wc-gateway-oen.php';
    require_once OEN_PAYMENT_PLUGIN_DIR . 'includes/class-wc-gateway-oen-credit.php';
    require_once OEN_PAYMENT_PLUGIN_DIR . 'includes/class-wc-gateway-oen-cvs.php';
    require_once OEN_PAYMENT_PLUGIN_DIR . 'includes/class-wc-gateway-oen-atm.php';
    require_once OEN_PAYMENT_PLUGIN_DIR . 'includes/class-oen-webhook-handler.php';
    require_once OEN_PAYMENT_PLUGIN_DIR . 'includes/class-oen-email-handler.php';
    require_once OEN_PAYMENT_PLUGIN_DIR . 'includes/class-oen-error-handler.php';

    if ( is_admin() && class_exists( 'WC_Settings_Page' ) ) {
        require_once OEN_PAYMENT_PLUGIN_DIR . 'includes/class-oen-settings.php';

        add_filter( 'woocommerce_get_settings_pages', function ( array $settings ): array {
            $settings[] = new OEN_Settings();
            return $settings;
        } );
    }

    add_filter( 'woocommerce_payment_gateways', function ( array $gateways ): array {
        if ( 'yes' === get_option( 'oen_enabled', 'no' ) ) {
            $gateways[] = WC_Gateway_OEN_Credit::class;
            $gateways[] = WC_Gateway_OEN_CVS::class;
        }
        return $gateways;
    } );

    new OEN_Webhook_Handler();
    new OEN_Email_Handler();
    new OEN_Error_Handler();
} );
