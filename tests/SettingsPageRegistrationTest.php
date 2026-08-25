<?php

declare( strict_types=1 );

/**
 * Locks the load order of the OEN settings page.
 *
 * WC_Settings_Page is not loadable at plugins_loaded: its file lives in
 * woocommerce/includes/admin/settings/ and WC_Autoloader has no path branch for the
 * wc_settings_page prefix. Two ways of getting this wrong have both shipped:
 *
 *   - requiring class-oen-settings.php at plugins_loaded fatals the whole site with
 *     "Class WC_Settings_Page not found" (its parent cannot be resolved); and
 *   - guarding that require on class_exists( 'WC_Settings_Page' ) makes the condition
 *     permanently false, so the settings page silently never registers and the
 *     merchant has no way to enter a merchant id or secret key.
 *
 * This harness boots the plugin with WC_Settings_Page deliberately absent and asserts
 * that the plugin still registers on woocommerce_get_settings_pages, defers loading the
 * class until the filter runs, and then appends a real settings page.
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['test_hooks'] = [];

if ( ! function_exists( 'add_action' ) ) {
    function add_action( string $hook, mixed $callback, int $priority = 10, int $args = 1 ): void {
        $GLOBALS['test_hooks'][ $hook ][] = $callback;
    }
}
if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( string $hook, mixed $callback, int $priority = 10, int $args = 1 ): void {
        $GLOBALS['test_hooks'][ $hook ][] = $callback;
    }
}
if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( string $file ): string {
        return dirname( $file ) . '/';
    }
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
    function plugin_dir_url( string $file ): string {
        return 'https://store.example/wp-content/plugins/woocommerce-oen-payment/';
    }
}
if ( ! function_exists( 'plugin_basename' ) ) {
    function plugin_basename( string $file ): string {
        return 'woocommerce-oen-payment/' . basename( $file );
    }
}
if ( ! function_exists( 'load_plugin_textdomain' ) ) {
    function load_plugin_textdomain( string $domain, bool $deprecated = false, string $path = '' ): bool {
        return true;
    }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( string $value ): string {
        return trim( strip_tags( $value ) );
    }
}
if ( ! function_exists( 'is_admin' ) ) {
    function is_admin(): bool {
        return true;
    }
}
if ( ! function_exists( 'wc_get_order' ) ) {
    function wc_get_order( mixed $order_id ): mixed {
        return null;
    }
}

// WooCommerce must look active so the plugin's dependency check passes.
if ( ! class_exists( 'WooCommerce', false ) ) {
    class WooCommerce {}
}

// The gateway classes extend this; the settings page's parent stays deliberately absent.
if ( ! class_exists( 'WC_Payment_Gateway', false ) ) {
    class WC_Payment_Gateway {
        public string $id = '';
        public string $method_title = '';
        public string $method_description = '';
        public string $icon = '';
        public string $title = '';
        public string $description = '';
        public string $enabled = '';
        public bool $has_fields = false;
        public array $supports = [];
        public array $form_fields = [];

        public function init_settings(): void {}

        public function get_option( string $key, mixed $default = '' ): mixed {
            return $default;
        }

        public function process_admin_options(): void {}
    }
}

test_assert(
    ! class_exists( 'WC_Settings_Page', false ),
    'precondition: WC_Settings_Page must be absent, mirroring plugins_loaded in a real request'
);

require_once dirname( __DIR__ ) . '/woocommerce-oen-payment.php';

test_assert(
    isset( $GLOBALS['test_hooks']['plugins_loaded'] ),
    'the plugin must hook plugins_loaded'
);

// Fire the plugins_loaded callback with WC_Settings_Page still absent. Before this fix
// this either fataled or skipped the registration entirely.
foreach ( $GLOBALS['test_hooks']['plugins_loaded'] as $callback ) {
    $callback();
}

test_assert(
    ! class_exists( 'OEN_Settings', false ),
    'OEN_Settings must NOT be loaded at plugins_loaded — its parent does not exist yet'
);

test_assert(
    isset( $GLOBALS['test_hooks']['woocommerce_get_settings_pages'] ),
    'the settings page must be registered on woocommerce_get_settings_pages even though '
        . 'WC_Settings_Page was unavailable at plugins_loaded'
);

// WooCommerce include_once's class-wc-settings-page.php before applying the filter, so
// by the time the callback runs the parent exists.
if ( ! class_exists( 'WC_Settings_Page', false ) ) {
    class WC_Settings_Page {
        public string $id = '';
        public string $label = '';

        public function __construct() {}

        public function add_settings_page( array $pages ): array {
            return $pages;
        }
    }
}

$pages = [];
foreach ( $GLOBALS['test_hooks']['woocommerce_get_settings_pages'] as $callback ) {
    $pages = $callback( $pages );
}

test_assert( 1 === count( $pages ), 'exactly one settings page must be appended' );
test_assert(
    $pages[0] instanceof OEN_Settings,
    'the appended page must be an OEN_Settings instance'
);
test_assert(
    'oen_payment' === $pages[0]->id,
    'the settings page id must stay oen_payment (the tab URL merchants bookmark)'
);

echo "Settings page registration harness passed.\n";
