<?php

declare( strict_types=1 );

/**
 * Unit tests for the ported WooCommerce Blocks integration (OEN_Blocks_Payment_Method).
 *
 * is_active() gates whether OEN shows in the block checkout; it must mirror the
 * classic gateway's is_available() (master toggle + credentials + per-gateway
 * enable + gateway class present). Uses a stub of the Blocks base class so the
 * plugin class can be instantiated without WooCommerce Blocks installed.
 */

namespace Automattic\WooCommerce\Blocks\Payments\Integrations {
    if ( ! class_exists( __NAMESPACE__ . '\\AbstractPaymentMethodType', false ) ) {
        abstract class AbstractPaymentMethodType {
            protected $name     = '';
            protected $settings = [];

            public function get_setting( string $key, $default = '' ) {
                return $this->settings[ $key ] ?? $default;
            }

            public function get_supported_features(): array {
                return [ 'products' ];
            }
        }
    }
}

namespace {
    require_once __DIR__ . '/bootstrap.php';

    if ( ! defined( 'OEN_PAYMENT_PLUGIN_URL' ) ) {
        define( 'OEN_PAYMENT_PLUGIN_URL', 'https://store.example/wp-content/plugins/woocommerce-oen-payment/' );
    }
    if ( ! defined( 'OEN_PAYMENT_VERSION' ) ) {
        define( 'OEN_PAYMENT_VERSION', 'test' );
    }

    if ( ! function_exists( 'wp_register_script' ) ) {
        function wp_register_script( string $handle, string $src, array $deps = [], $ver = false, bool $in_footer = false ): bool {
            $GLOBALS['test_registered_scripts'][ $handle ] = [ 'src' => $src, 'deps' => $deps, 'ver' => $ver ];
            return true;
        }
    }

    // Stub the concrete gateway class that is_active() probes via class_exists().
    if ( ! class_exists( 'WC_Gateway_OEN_Credit', false ) ) {
        class WC_Gateway_OEN_Credit {}
    }

    require_once __DIR__ . '/../includes/class-oen-blocks-payment-method.php';

    function blk_reset( array $options = [] ): void {
        $GLOBALS['test_options']            = $options;
        $GLOBALS['test_registered_scripts'] = [];
    }

    function blk_active_options(): array {
        return [
            'oen_enabled'                     => 'yes',
            'oen_merchant_id'                 => 'merchant-1',
            'oen_api_token'                   => 'sk_test_x',
            'woocommerce_oen_credit_settings' => [ 'enabled' => 'yes', 'title' => 'OEN Credit', 'description' => 'Pay by card' ],
        ];
    }

    function blk_make( string $name = 'oen_credit', string $gateway = 'WC_Gateway_OEN_Credit' ): OEN_Blocks_Payment_Method {
        $method = new OEN_Blocks_Payment_Method( $name, $gateway );
        $method->initialize();
        return $method;
    }

    function test_block_active_when_all_conditions_met(): void {
        blk_reset( blk_active_options() );
        test_assert( true === blk_make()->is_active(), 'Block method should be active when master + credentials + per-gateway enable + gateway class are all present.' );
    }

    function test_block_inactive_when_master_toggle_off(): void {
        $options                = blk_active_options();
        $options['oen_enabled'] = 'no';
        blk_reset( $options );
        test_assert( false === blk_make()->is_active(), 'Block method must be inactive when the master oen_enabled toggle is off.' );
    }

    function test_block_inactive_when_credentials_missing(): void {
        $options                  = blk_active_options();
        $options['oen_api_token'] = '';
        blk_reset( $options );
        test_assert( false === blk_make()->is_active(), 'Block method must be inactive when the secret key is missing.' );
    }

    function test_block_inactive_when_gateway_disabled(): void {
        $options                                    = blk_active_options();
        $options['woocommerce_oen_credit_settings'] = [ 'enabled' => 'no' ];
        blk_reset( $options );
        test_assert( false === blk_make()->is_active(), 'Block method must be inactive when the per-gateway enable is off.' );
    }

    function test_block_inactive_when_gateway_class_missing(): void {
        $options                                   = blk_active_options();
        $options['woocommerce_oen_ghost_settings'] = [ 'enabled' => 'yes' ];
        blk_reset( $options );
        test_assert( false === blk_make( 'oen_ghost', 'WC_Gateway_OEN_Nonexistent' )->is_active(), 'Block method must be inactive when its gateway class does not exist, even if otherwise enabled.' );
    }

    function test_block_payment_method_data_shape(): void {
        blk_reset( blk_active_options() );
        $data = blk_make()->get_payment_method_data();
        test_assert( 'OEN Credit' === ( $data['title'] ?? null ), 'get_payment_method_data() title should come from the gateway settings.' );
        test_assert( array_key_exists( 'description', $data ), 'get_payment_method_data() should include description.' );
        test_assert( array_key_exists( 'supports', $data ), 'get_payment_method_data() should include supports (feature list).' );
    }

    function test_block_script_handle_registered_with_registry_dependency(): void {
        blk_reset( blk_active_options() );
        $handles = blk_make()->get_payment_method_script_handles();
        test_assert( [ 'oen-blocks-payment-method' ] === $handles, 'get_payment_method_script_handles() should return the block script handle.' );
        $registered = $GLOBALS['test_registered_scripts']['oen-blocks-payment-method'] ?? null;
        test_assert( is_array( $registered ), 'The block payment method script should be registered.' );
        test_assert( in_array( 'wc-blocks-registry', $registered['deps'], true ), 'The block script must depend on wc-blocks-registry.' );
    }

    test_block_active_when_all_conditions_met();
    test_block_inactive_when_master_toggle_off();
    test_block_inactive_when_credentials_missing();
    test_block_inactive_when_gateway_disabled();
    test_block_inactive_when_gateway_class_missing();
    test_block_payment_method_data_shape();
    test_block_script_handle_registered_with_registry_dependency();

    echo "Blocks payment method harness passed.\n";
}
