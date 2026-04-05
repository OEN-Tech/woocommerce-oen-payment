<?php

declare( strict_types=1 );

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-oen-webhook-parser.php';

function test_parser_verifies_signature_and_returns_nested_event_data(): void {
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
        'Parser should return the nested data payload.'
    );
    test_assert(
        ( $payload['orderId'] ?? null ) === 'wc_1001',
        'Parser should return orderId from event data.'
    );
    test_assert(
        ( $payload['transactionHid'] ?? null ) === 'txn_hid_123',
        'Parser should return transactionHid from event data.'
    );
    test_assert(
        ( $payload['status'] ?? null ) === 'charged',
        'Parser should return status from event data.'
    );
}

test_parser_verifies_signature_and_returns_nested_event_data();

echo "Webhook parser smoke harness passed.\n";
