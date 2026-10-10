<?php

declare( strict_types=1 );

/*
 * The plugin source files declare strict_types, so a call from one plugin class to another no
 * longer coerces a scalar of the wrong type: an integer where a string is declared throws
 * TypeError instead of being converted.
 *
 * WordPress returns an option with the type it was saved with. An option written earlier in
 * the same request comes back as the integer or boolean that was passed to update_option(),
 * not as the string it becomes in the database. These harnesses pin the places where such an
 * option value crosses into a typed plugin parameter.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-oen-api-client.php';
require_once __DIR__ . '/../includes/class-oen-webhook-handler.php';

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( mixed $value ): string {
        if ( is_scalar( $value ) ) {
            return trim( (string) $value );
        }

        return '';
    }
}

if ( ! function_exists( 'add_action' ) ) {
    function add_action( string $hook, array $callback ): void {}
}

function test_from_settings_accepts_options_saved_as_integers(): void {
    $GLOBALS['test_options'] = [
        'oen_merchant_id' => 12345,
        'oen_api_token'   => 987654,
    ];

    $client = OEN_API_Client::from_settings();

    test_assert(
        'https://12345.oen.tw/checkout/cs_1' === $client->get_checkout_url( 'cs_1' ),
        'from_settings() should use a merchant id option that was saved as an integer.'
    );

    $GLOBALS['test_options'] = [];
}

function test_webhook_secret_saved_as_an_integer_still_verifies_signatures(): void {
    $GLOBALS['test_options'] = [
        'oen_webhook_secret' => 424242,
    ];

    $body      = (string) json_encode( [
        'type' => 'checkout_session.completed',
        'data' => [ 'orderId' => 'wc-1' ],
    ] );
    $timestamp = (string) time();

    $_SERVER['HTTP_OENPAY_SIGNATURE'] = 't=' . $timestamp . ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $body, '424242' );

    $handler = new OEN_Webhook_Handler();
    $parse   = new ReflectionMethod( OEN_Webhook_Handler::class, 'parse_webhook_payload' );
    $parsed  = $parse->invoke( $handler, $body );

    test_assert(
        'checkout_session.completed' === ( $parsed['type'] ?? null ),
        'A webhook secret saved as an integer should still verify the signature of an incoming event.'
    );

    unset( $_SERVER['HTTP_OENPAY_SIGNATURE'] );
    $GLOBALS['test_options'] = [];
}

test_from_settings_accepts_options_saved_as_integers();
test_webhook_secret_saved_as_an_integer_still_verifies_signatures();

echo "Strict types harness passed.\n";
