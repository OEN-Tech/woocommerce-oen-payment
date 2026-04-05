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
                'id'          => 'sess_123',
                'checkoutUrl' => 'https://oen.tw/checkout/sess_123',
            ],
        ] ),
    ];

    test_assert(
        method_exists( $client, 'create_session' ),
        'OEN_API_Client::create_session() should exist for the Hosted Checkout contract.'
    );

    $result = $client->create_session( [
        'amount'    => 1234,
        'currency'  => 'TWD',
        'cancelUrl' => 'https://store.example/cart',
    ] );

    test_assert(
        ( $GLOBALS['test_http_post_calls'][0]['url'] ?? null ) === 'https://api.oen.tw/hosted-checkout/v1/sessions',
        'POST should hit /hosted-checkout/v1/sessions.'
    );
    test_assert(
        ( $GLOBALS['test_http_post_calls'][0]['args']['headers']['Authorization'] ?? null ) === 'Bearer sk_test_secret',
        'POST authorization should use the secret key.'
    );
    test_assert(
        ( $GLOBALS['test_http_post_calls'][0]['args']['headers']['Content-Type'] ?? null ) === 'application/json',
        'POST should send JSON content type.'
    );
    test_assert(
        is_string( $GLOBALS['test_http_post_calls'][0]['args']['headers']['Idempotency-Key'] ?? null )
            && '' !== ( $GLOBALS['test_http_post_calls'][0]['args']['headers']['Idempotency-Key'] ?? '' ),
        'POST should send a non-empty Idempotency-Key header.'
    );
    $decoded_body = json_decode( (string) ( $GLOBALS['test_http_post_calls'][0]['args']['body'] ?? '' ), true );
    test_assert(
        json_last_error() === JSON_ERROR_NONE,
        'POST body should decode as JSON.'
    );
    test_assert(
        isset( $decoded_body['amount'], $decoded_body['orderId'], $decoded_body['currency'] ),
        'POST payload should include amount, orderId, and currency.'
    );
    test_assert(
        ! array_key_exists( 'merchantId', $decoded_body ),
        'POST payload should not auto-inject merchantId for the Hosted Checkout v1 secret-key contract.'
    );
    test_assert(
        ( $decoded_body['cancelUrl'] ?? null ) === 'https://store.example/cart',
        'POST payload should preserve cancelUrl when the gateway provides it.'
    );
    test_assert(
        ( $result['id'] ?? null ) === 'sess_123',
        'create_session() should return a non-empty session id because the gateway depends on it for stale-attempt protection.'
    );
    test_assert(
        ( $result['checkoutUrl'] ?? null ) === 'https://oen.tw/checkout/sess_123',
        'create_session() should return checkoutUrl.'
    );
}

function test_create_session_rejects_missing_session_id(): void {
    test_reset_http_stubs();

    $client = new OEN_API_Client( 'merchant-123', 'sk_test_secret' );

    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
            'code' => 'S0000',
            'data' => [
                'checkoutUrl' => 'https://oen.tw/checkout/sess_missing',
            ],
        ] ),
    ];

    try {
        $client->create_session( [
            'amount'   => 1234,
            'currency' => 'TWD',
        ] );
        throw new RuntimeException( 'create_session() should reject Hosted Checkout responses that omit session id.' );
    } catch ( RuntimeException $exception ) {
        test_assert(
            'OEN Payment API did not return a session id.' === $exception->getMessage(),
            'Hosted Checkout create responses must include a non-empty session id.'
        );
    }
}

function test_create_session_uses_unique_idempotency_key_per_attempt(): void {
    test_reset_http_stubs();

    $client = new OEN_API_Client( 'merchant-123', 'sk_test_secret' );

    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
            'code' => 'S0000',
            'data' => [
                'id'          => 'sess_123',
                'checkoutUrl' => 'https://oen.tw/checkout/sess_123',
            ],
        ] ),
    ];
    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
            'code' => 'S0000',
            'data' => [
                'id'          => 'sess_456',
                'checkoutUrl' => 'https://oen.tw/checkout/sess_456',
            ],
        ] ),
    ];

    $params = [
        'amount'   => 1234,
        'currency' => 'TWD',
        'orderId'  => 'wc-order-1001',
    ];

    $client->create_session( $params );
    $client->create_session( $params );

    $first_key  = $GLOBALS['test_http_post_calls'][0]['args']['headers']['Idempotency-Key'] ?? null;
    $second_key = $GLOBALS['test_http_post_calls'][1]['args']['headers']['Idempotency-Key'] ?? null;

    test_assert(
        is_string( $first_key ) && is_string( $second_key ),
        'Each create_session() attempt should send an Idempotency-Key header.'
    );
    test_assert(
        $first_key !== $second_key,
        'Idempotency-Key should be unique per checkout attempt, even for the same orderId.'
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
        ( $GLOBALS['test_http_get_calls'][0]['url'] ?? null ) === 'https://api.oen.tw/hosted-checkout/v1/sessions/sess_123',
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
test_create_session_rejects_missing_session_id();
test_create_session_uses_unique_idempotency_key_per_attempt();
test_get_session_uses_hosted_checkout_contract();

echo "API client smoke harness passed.\n";
