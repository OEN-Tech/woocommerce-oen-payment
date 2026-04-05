<?php

declare( strict_types=1 );

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-oen-webhook-parser.php';

function test_parser_verifies_signature_and_returns_event_type_and_nested_event_data(): void {
    $secret    = 'whsec_test_secret';
    $timestamp = '1712345678';
    $raw_body  = wp_json_encode( [
        'id'   => 'evt_test_123',
        'type' => 'payment.succeeded',
        'data' => [
            'sessionId'       => 'sess_123',
            'orderId'         => 'wc_1001',
            'transactionHid'  => 'txn_hid_123',
            'transactionId'   => 'txn_123',
            'status'          => 'charged',
            'paymentMethod'   => 'card',
            'paymentProvider' => 'oenpay',
        ],
    ] );

    test_assert( is_string( $raw_body ), 'Webhook body should encode to JSON.' );

    $signature = hash_hmac( 'sha256', $timestamp . '.' . $raw_body, $secret );
    $header    = 't=' . $timestamp . ',v1=' . $signature;

    $parser  = new OEN_Webhook_Parser( $secret );
    $payload = $parser->parse( $raw_body, $header );

    test_assert(
        is_array( $payload ),
        'Parser should return the parsed event envelope.'
    );
    test_assert(
        ( $payload['type'] ?? null ) === 'payment.succeeded',
        'Parser should preserve the event type.'
    );
    test_assert(
        isset( $payload['data'] ) && is_array( $payload['data'] ),
        'Parser should return nested event data.'
    );
    test_assert(
        ( $payload['data']['orderId'] ?? null ) === 'wc_1001',
        'Parser should return orderId from event data.'
    );
    test_assert(
        ( $payload['data']['transactionHid'] ?? null ) === 'txn_hid_123',
        'Parser should return transactionHid from event data.'
    );
    test_assert(
        ( $payload['data']['status'] ?? null ) === 'charged',
        'Parser should return status from event data.'
    );
}

function test_parser_rejects_invalid_signature(): void {
    $secret    = 'whsec_test_secret';
    $timestamp = '1712345678';
    $raw_body  = wp_json_encode( [
        'id'   => 'evt_test_456',
        'type' => 'payment.failed',
        'data' => [
            'orderId'        => 'wc_1002',
            'transactionHid' => 'txn_hid_456',
        ],
    ] );

    test_assert( is_string( $raw_body ), 'Webhook body should encode to JSON.' );

    $parser = new OEN_Webhook_Parser( $secret );

    try {
        $parser->parse( $raw_body, 't=' . $timestamp . ',v1=invalidsignature' );
        throw new RuntimeException( 'Parser should reject an invalid signature.' );
    } catch ( RuntimeException $exception ) {
        test_assert(
            'Invalid webhook signature' === $exception->getMessage(),
            'Parser should reject invalid webhook signatures.'
        );
        test_assert(
            403 === $exception->getCode(),
            'Invalid signatures should return HTTP 403 semantics.'
        );
    }
}

test_parser_verifies_signature_and_returns_event_type_and_nested_event_data();
test_parser_rejects_invalid_signature();

echo "Webhook parser smoke harness passed.\n";
