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

const REG_URL = 'https://store.example/?wc-api=oen_payment';

function reg_reset(): void {
    $GLOBALS['test_options']            = [];
    $GLOBALS['test_http_post_calls']    = [];
    $GLOBALS['test_http_post_queue']    = [];
    $GLOBALS['test_http_get_calls']     = [];
    $GLOBALS['test_http_get_queue']     = [];
    $GLOBALS['test_http_request_calls'] = [];
    $GLOBALS['test_http_request_queue'] = [];
    $GLOBALS['test_admin_errors']       = [];
    $GLOBALS['test_admin_messages']     = [];
}

function reg_creds(): void {
    $GLOBALS['test_options']['oen_enabled']     = 'yes';
    $GLOBALS['test_options']['oen_merchant_id'] = 'merchant-123';
    $GLOBALS['test_options']['oen_api_token']   = 'sk_test_secret';
}

function reg_queue( string $kind, array $body, int $code = 200 ): void {
    $GLOBALS[ 'test_http_' . $kind . '_queue' ][] = [
        'response' => [ 'code' => $code ],
        'body'     => wp_json_encode( $body ),
    ];
}

function test_registers_webhook_on_first_save(): void {
    reg_reset();
    reg_creds();
    reg_queue( 'get', [ 'items' => [] ] );                          // list -> none
    reg_queue( 'post', [ 'id' => 'whk_1', 'secret' => 'whsec_1' ] ); // create

    ( new OEN_Settings() )->save();

    test_assert(
        1 === count( $GLOBALS['test_http_post_calls'] ),
        'First save should create exactly one webhook when none exists.'
    );
    test_assert(
        ( $GLOBALS['test_http_post_calls'][0]['url'] ?? null ) === 'https://api.oen.tw/api/hosted-checkout/v1/webhooks',
        'Webhook creation should POST to the /api/hosted-checkout/v1/webhooks endpoint.'
    );
    $body = json_decode( (string) ( $GLOBALS['test_http_post_calls'][0]['args']['body'] ?? '' ), true );
    test_assert(
        ( $body['url'] ?? null ) === REG_URL
            && in_array( 'refund.succeeded', $body['enabledEvents'] ?? [], true ),
        'Creation should send the site webhook URL and the handled events.'
    );
    test_assert(
        ( $GLOBALS['test_options']['oen_webhook_id'] ?? null ) === 'whk_1'
            && ( $GLOBALS['test_options']['oen_webhook_secret'] ?? null ) === 'whsec_1',
        'The webhook id and signing secret should be stored automatically.'
    );
    test_assert(
        1 === count( $GLOBALS['test_admin_messages'] ),
        'A success notice should be shown.'
    );
}

function test_skips_when_already_registered(): void {
    reg_reset();
    reg_creds();
    $GLOBALS['test_options']['oen_webhook_id']     = 'whk_existing';
    $GLOBALS['test_options']['oen_webhook_secret'] = 'whsec_existing';

    ( new OEN_Settings() )->save();

    test_assert(
        0 === count( $GLOBALS['test_http_get_calls'] )
            && 0 === count( $GLOBALS['test_http_post_calls'] )
            && 0 === count( $GLOBALS['test_http_request_calls'] ),
        'An already-registered webhook should make no API calls on an ordinary save.'
    );
}

function test_legacy_secret_not_clobbered_on_ordinary_save(): void {
    reg_reset();
    reg_creds();
    $GLOBALS['test_options']['oen_webhook_secret'] = 'manual_secret'; // set by hand, no id

    ( new OEN_Settings() )->save();

    test_assert(
        0 === count( $GLOBALS['test_http_post_calls'] ) && 0 === count( $GLOBALS['test_http_get_calls'] ),
        'A manually configured secret with no tracked id must not trigger registration on an ordinary save.'
    );
    test_assert(
        ( $GLOBALS['test_options']['oen_webhook_secret'] ?? null ) === 'manual_secret',
        'The manual secret must be left untouched.'
    );
}

function test_force_reregister_updates_and_rotates_existing(): void {
    reg_reset();
    reg_creds();
    $GLOBALS['test_options']['oen_webhook_id']         = 'whk_existing';
    $GLOBALS['test_options']['oen_webhook_secret']     = 'whsec_old';
    $GLOBALS['test_options']['oen_webhook_reregister'] = 'yes';
    reg_queue( 'get', [ 'items' => [ [ 'id' => 'whk_existing', 'url' => REG_URL ] ] ] ); // list -> match
    reg_queue( 'request', [ 'id' => 'whk_existing', 'url' => REG_URL ] );                // update (PUT)
    reg_queue( 'post', [ 'id' => 'whk_existing', 'secret' => 'whsec_rotated' ] );        // rotate-secret

    ( new OEN_Settings() )->save();

    test_assert(
        1 === count( $GLOBALS['test_http_request_calls'] ),
        'Re-registering an existing webhook should update it in place (PUT), not create a duplicate.'
    );
    test_assert(
        str_ends_with( (string) ( $GLOBALS['test_http_post_calls'][0]['url'] ?? '' ), '/webhooks/whk_existing/rotate-secret' ),
        'Re-register should rotate the existing webhook secret.'
    );
    test_assert(
        ( $GLOBALS['test_options']['oen_webhook_secret'] ?? null ) === 'whsec_rotated'
            && ( $GLOBALS['test_options']['oen_webhook_id'] ?? null ) === 'whk_existing',
        'The rotated secret should be stored and the webhook id preserved.'
    );
    test_assert(
        ( $GLOBALS['test_options']['oen_webhook_reregister'] ?? null ) === 'no',
        'The re-register flag should reset after running.'
    );
}

function test_force_reregister_adopts_legacy_webhook_by_url(): void {
    reg_reset();
    reg_creds();
    $GLOBALS['test_options']['oen_webhook_secret']     = 'manual_secret'; // legacy, no id
    $GLOBALS['test_options']['oen_webhook_reregister'] = 'yes';
    reg_queue( 'get', [ 'items' => [ [ 'id' => 'whk_legacy', 'url' => REG_URL ] ] ] );
    reg_queue( 'request', [ 'id' => 'whk_legacy', 'url' => REG_URL ] );
    reg_queue( 'post', [ 'id' => 'whk_legacy', 'secret' => 'whsec_rotated' ] );

    ( new OEN_Settings() )->save();

    test_assert(
        0 === count( $GLOBALS['test_http_post_calls'] ) || ! str_ends_with( (string) ( $GLOBALS['test_http_post_calls'][0]['url'] ?? '' ), '/webhooks' ),
        'Adopting an existing webhook by URL must not create a new one.'
    );
    test_assert(
        ( $GLOBALS['test_options']['oen_webhook_id'] ?? null ) === 'whk_legacy'
            && ( $GLOBALS['test_options']['oen_webhook_secret'] ?? null ) === 'whsec_rotated',
        'The legacy webhook should be adopted (id stored, secret rotated).'
    );
}

function test_force_reregister_creates_when_no_match(): void {
    reg_reset();
    reg_creds();
    $GLOBALS['test_options']['oen_webhook_id']         = 'whk_old';
    $GLOBALS['test_options']['oen_webhook_reregister'] = 'yes';
    reg_queue( 'get', [ 'items' => [ [ 'id' => 'whk_old', 'url' => 'https://old.example/?wc-api=oen_payment' ] ] ] );
    reg_queue( 'post', [ 'id' => 'whk_new', 'secret' => 'whsec_new' ] );

    ( new OEN_Settings() )->save();

    test_assert(
        1 === count( $GLOBALS['test_http_post_calls'] )
            && str_ends_with( (string) ( $GLOBALS['test_http_post_calls'][0]['url'] ?? '' ), '/webhooks' ),
        'When no webhook matches the current URL, re-register should create a new one.'
    );
    test_assert(
        ( $GLOBALS['test_options']['oen_webhook_id'] ?? null ) === 'whk_new'
            && ( $GLOBALS['test_options']['oen_webhook_secret'] ?? null ) === 'whsec_new',
        'The newly created webhook id and secret should be stored.'
    );
}

function test_skips_and_resets_flag_when_disabled_or_no_credentials(): void {
    reg_reset();
    $GLOBALS['test_options']['oen_enabled']            = 'no';
    $GLOBALS['test_options']['oen_merchant_id']        = 'merchant-123';
    $GLOBALS['test_options']['oen_api_token']          = 'sk_test_secret';
    $GLOBALS['test_options']['oen_webhook_reregister'] = 'yes';

    ( new OEN_Settings() )->save();

    test_assert(
        0 === count( $GLOBALS['test_http_get_calls'] ) && 0 === count( $GLOBALS['test_http_post_calls'] ),
        'A disabled gateway should make no API calls.'
    );
    test_assert(
        ( $GLOBALS['test_options']['oen_webhook_reregister'] ?? null ) === 'no',
        'The re-register flag must reset even when the save skips registration.'
    );

    reg_reset();
    $GLOBALS['test_options']['oen_enabled'] = 'yes';
    ( new OEN_Settings() )->save();
    test_assert(
        0 === count( $GLOBALS['test_http_get_calls'] ),
        'Missing MerchantID/Secret Key should make no API calls.'
    );
}

function test_registration_failure_surfaces_error_and_stores_nothing(): void {
    reg_reset();
    reg_creds();
    $GLOBALS['test_options']['oen_webhook_reregister'] = 'yes';
    reg_queue( 'get', [ 'error' => [ 'code' => 'INVALID_KEY', 'message' => 'bad key' ] ], 400 ); // list fails

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
test_skips_when_already_registered();
test_legacy_secret_not_clobbered_on_ordinary_save();
test_force_reregister_updates_and_rotates_existing();
test_force_reregister_adopts_legacy_webhook_by_url();
test_force_reregister_creates_when_no_match();
test_skips_and_resets_flag_when_disabled_or_no_credentials();
test_registration_failure_surfaces_error_and_stores_nothing();

echo "Webhook registration harness passed.\n";
