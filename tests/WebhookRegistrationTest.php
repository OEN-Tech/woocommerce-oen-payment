<?php

declare( strict_types=1 );

require_once __DIR__ . '/bootstrap.php';

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( mixed $value ): string {
        return is_scalar( $value ) ? trim( (string) $value ) : '';
    }
}

if ( ! function_exists( 'is_admin' ) ) {
    function is_admin(): bool {
        return true;
    }
}

if ( ! function_exists( 'home_url' ) ) {
    function home_url( string $path = '' ): string {
        return 'https://store.example' . $path;
    }
}

if ( ! function_exists( 'add_query_arg' ) ) {
    function add_query_arg( string $key, string $value, string $url ): string {
        $separator = str_contains( $url, '?' ) ? '&' : '?';
        return $url . $separator . $key . '=' . $value;
    }
}

if ( ! function_exists( 'update_option' ) ) {
    function update_option( string $name, mixed $value ): bool {
        $GLOBALS['test_options'][ $name ] = $value;
        return true;
    }
}

if ( ! class_exists( 'WC_Settings_Page', false ) ) {
    class WC_Settings_Page {
        public string $id    = '';
        public string $label = '';

        public function __construct() {}

        public function save(): void {}
    }
}

if ( ! class_exists( 'WC_Admin_Settings', false ) ) {
    class WC_Admin_Settings {
        public static function add_error( string $message ): void {
            $GLOBALS['test_admin_errors'][] = $message;
        }

        public static function add_message( string $message ): void {
            $GLOBALS['test_admin_messages'][] = $message;
        }
    }
}

require_once __DIR__ . '/../includes/class-oen-api-client.php';
require_once __DIR__ . '/../includes/class-oen-settings.php';

function reg_reset(): void {
    $GLOBALS['test_options']          = [];
    $GLOBALS['test_http_post_calls']  = [];
    $GLOBALS['test_http_post_queue']  = [];
    $GLOBALS['test_admin_errors']     = [];
    $GLOBALS['test_admin_messages']   = [];
}

function reg_enqueue_webhook_response( string $id, string $secret ): void {
    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [ 'id' => $id, 'secret' => $secret, 'enabledEvents' => [ 'refund.succeeded' ] ] ),
    ];
}

function test_registers_webhook_on_first_save(): void {
    reg_reset();
    $GLOBALS['test_options']['oen_enabled']     = 'yes';
    $GLOBALS['test_options']['oen_merchant_id'] = 'merchant-123';
    $GLOBALS['test_options']['oen_api_token']   = 'sk_test_secret';
    reg_enqueue_webhook_response( 'whk_1', 'whsec_1' );

    ( new OEN_Settings() )->save();

    test_assert(
        1 === count( $GLOBALS['test_http_post_calls'] ),
        'First save with credentials and no stored webhook should register exactly one webhook.'
    );
    test_assert(
        ( $GLOBALS['test_http_post_calls'][0]['url'] ?? null ) === 'https://api.oen.tw/api/hosted-checkout/v1/webhooks',
        'Webhook registration should POST to the /api/hosted-checkout/v1/webhooks endpoint.'
    );
    $body = json_decode( (string) ( $GLOBALS['test_http_post_calls'][0]['args']['body'] ?? '' ), true );
    test_assert(
        ( $body['url'] ?? null ) === 'https://store.example/?wc-api=oen_payment',
        'Registration should send the site webhook endpoint URL.'
    );
    test_assert(
        in_array( 'refund.succeeded', $body['enabledEvents'] ?? [], true )
            && in_array( 'checkout_session.completed', $body['enabledEvents'] ?? [], true ),
        'Registration should subscribe to the events the plugin handles.'
    );
    test_assert(
        ( $GLOBALS['test_options']['oen_webhook_id'] ?? null ) === 'whk_1'
            && ( $GLOBALS['test_options']['oen_webhook_secret'] ?? null ) === 'whsec_1',
        'The returned webhook id and signing secret should be stored automatically.'
    );
    test_assert(
        1 === count( $GLOBALS['test_admin_messages'] ),
        'A success notice should be shown after registration.'
    );
}

function test_skips_registration_when_already_registered(): void {
    reg_reset();
    $GLOBALS['test_options']['oen_enabled']     = 'yes';
    $GLOBALS['test_options']['oen_merchant_id'] = 'merchant-123';
    $GLOBALS['test_options']['oen_api_token']   = 'sk_test_secret';
    $GLOBALS['test_options']['oen_webhook_id']  = 'whk_existing';
    reg_enqueue_webhook_response( 'whk_new', 'whsec_new' );

    ( new OEN_Settings() )->save();

    test_assert(
        0 === count( $GLOBALS['test_http_post_calls'] ),
        'An already-registered webhook should not be re-registered on an ordinary save.'
    );
    test_assert(
        ( $GLOBALS['test_options']['oen_webhook_id'] ?? null ) === 'whk_existing',
        'The existing webhook id should be left untouched.'
    );
}

function test_force_reregister_creates_new_webhook(): void {
    reg_reset();
    $GLOBALS['test_options']['oen_enabled']            = 'yes';
    $GLOBALS['test_options']['oen_merchant_id']        = 'merchant-123';
    $GLOBALS['test_options']['oen_api_token']          = 'sk_test_secret';
    $GLOBALS['test_options']['oen_webhook_id']         = 'whk_existing';
    $GLOBALS['test_options']['oen_webhook_reregister'] = 'yes';
    reg_enqueue_webhook_response( 'whk_new', 'whsec_new' );

    ( new OEN_Settings() )->save();

    test_assert(
        1 === count( $GLOBALS['test_http_post_calls'] ),
        'Ticking re-register should register a new webhook even when one already exists.'
    );
    test_assert(
        ( $GLOBALS['test_options']['oen_webhook_id'] ?? null ) === 'whk_new'
            && ( $GLOBALS['test_options']['oen_webhook_secret'] ?? null ) === 'whsec_new',
        'Re-registration should replace the stored webhook id and secret.'
    );
    test_assert(
        ( $GLOBALS['test_options']['oen_webhook_reregister'] ?? null ) === 'no',
        'The re-register flag should reset itself after running.'
    );
}

function test_skips_registration_when_disabled_or_missing_credentials(): void {
    reg_reset();
    $GLOBALS['test_options']['oen_enabled']     = 'no';
    $GLOBALS['test_options']['oen_merchant_id'] = 'merchant-123';
    $GLOBALS['test_options']['oen_api_token']   = 'sk_test_secret';
    reg_enqueue_webhook_response( 'whk_1', 'whsec_1' );
    ( new OEN_Settings() )->save();
    test_assert(
        0 === count( $GLOBALS['test_http_post_calls'] ),
        'A disabled gateway should not register a webhook.'
    );

    reg_reset();
    $GLOBALS['test_options']['oen_enabled'] = 'yes';
    reg_enqueue_webhook_response( 'whk_1', 'whsec_1' );
    ( new OEN_Settings() )->save();
    test_assert(
        0 === count( $GLOBALS['test_http_post_calls'] ),
        'Missing MerchantID/Secret Key should not register a webhook.'
    );
}

function test_registration_failure_surfaces_error_and_stores_nothing(): void {
    reg_reset();
    $GLOBALS['test_options']['oen_enabled']     = 'yes';
    $GLOBALS['test_options']['oen_merchant_id'] = 'merchant-123';
    $GLOBALS['test_options']['oen_api_token']   = 'sk_test_secret';
    $GLOBALS['test_options']['oen_webhook_reregister'] = 'yes';
    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 400 ],
        'body'     => wp_json_encode( [ 'error' => [ 'code' => 'INVALID_KEY', 'message' => 'bad key' ] ] ),
    ];

    ( new OEN_Settings() )->save();

    test_assert(
        1 === count( $GLOBALS['test_admin_errors'] ),
        'A registration failure should surface a settings error.'
    );
    test_assert(
        ! isset( $GLOBALS['test_options']['oen_webhook_id'] ),
        'No webhook id should be stored when registration fails.'
    );
    test_assert(
        ( $GLOBALS['test_options']['oen_webhook_reregister'] ?? null ) === 'no',
        'The re-register flag should reset even when registration fails.'
    );
}

test_registers_webhook_on_first_save();
test_skips_registration_when_already_registered();
test_force_reregister_creates_new_webhook();
test_skips_registration_when_disabled_or_missing_credentials();
test_registration_failure_surfaces_error_and_stores_nothing();

echo "Webhook registration harness passed.\n";
