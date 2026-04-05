<?php

declare( strict_types=1 );

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-oen-api-client.php';

function test_reset_http_stubs(): void {
    $GLOBALS['test_http_post_calls'] = [];
    $GLOBALS['test_http_get_calls']  = [];
    $GLOBALS['test_http_post_queue'] = [];
    $GLOBALS['test_http_get_queue']   = [];
}

function test_create_session_uses_hosted_checkout_contract(): void {
    test_reset_http_stubs();

    $client = new OEN_API_Client( 'merchant-123', 'sk_test_secret' );

    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
            'code' => 'S0000',
            'data' => [
                'checkoutUrl' => 'https://oen.tw/checkout/sess_123',
            ],
        ] ),
    ];

    test_assert(
        method_exists( $client, 'create_session' ),
        'OEN_API_Client::create_session() should exist for the Hosted Checkout contract.'
    );

    $result = $client->create_session( [
        'amount'   => 1234,
        'currency' => 'TWD',
    ] );

    test_assert(
        ( $GLOBALS['test_http_post_calls'][0]['url'] ?? null ) === 'https://payment-api.oen.tw/hosted-checkout/v1/sessions',
        'POST should hit /hosted-checkout/v1/sessions.'
    );
    test_assert(
        ( $GLOBALS['test_http_post_calls'][0]['args']['headers']['Authorization'] ?? null ) === 'Bearer sk_test_secret',
        'POST authorization should use the secret key.'
    );
    test_assert(
        ( $result['checkoutUrl'] ?? null ) === 'https://oen.tw/checkout/sess_123',
        'create_session() should return checkoutUrl.'
    );
}

function test_get_session_uses_hosted_checkout_contract(): void {
    test_reset_http_stubs();

    $client = new OEN_API_Client( 'merchant-123', 'sk_test_secret' );

    $GLOBALS['test_http_get_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
            'code' => 'S0000',
            'data' => [
                'id'     => 'sess_123',
                'status' => 'pending',
            ],
        ] ),
    ];

    test_assert(
        method_exists( $client, 'get_session' ),
        'OEN_API_Client::get_session() should exist for the Hosted Checkout contract.'
    );

    $result = $client->get_session( 'sess_123' );

    test_assert(
        ( $GLOBALS['test_http_get_calls'][0]['url'] ?? null ) === 'https://payment-api.oen.tw/hosted-checkout/v1/sessions/sess_123',
        'GET should hit /hosted-checkout/v1/sessions/{id}.'
    );
    test_assert(
        ( $GLOBALS['test_http_get_calls'][0]['args']['headers']['Authorization'] ?? null ) === 'Bearer sk_test_secret',
        'GET authorization should use the secret key.'
    );
    test_assert(
        ( $result['status'] ?? null ) === 'pending',
        'get_session() should return session status.'
    );
}

test_create_session_uses_hosted_checkout_contract();
test_get_session_uses_hosted_checkout_contract();

echo "API client smoke harness passed.\n";
