<?php

declare( strict_types=1 );

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-oen-webhook-parser.php';

if ( ! function_exists( 'add_action' ) ) {
    function add_action( string $hook, array $callback ): void {
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( string $value ): string {
        return trim( wp_strip_all_tags( $value ) );
    }
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( string $value ): string {
        return strip_tags( $value );
    }
}

require_once __DIR__ . '/../includes/class-oen-webhook-handler.php';

function test_parser_verifies_signature_and_returns_event_type_and_nested_event_data(): void {
    $secret    = 'whsec_test_secret';
    $timestamp = (string) time();
    $raw_body  = wp_json_encode( [
        'id'   => 'evt_test_123',
        'type' => 'checkout_session.completed',
        'data' => [
            'id'              => 'sess_123',
            'orderId'         => 'wc_1001',
            'transactionHid'  => 'txn_hid_123',
            'transactionId'   => 'txn_123',
            'status'          => 'completed',
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
        ( $payload['type'] ?? null ) === 'checkout_session.completed',
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
        ( $payload['data']['id'] ?? null ) === 'sess_123',
        'Parser should preserve the Hosted Checkout session id at data.id.'
    );
    test_assert(
        ( $payload['data']['transactionHid'] ?? null ) === 'txn_hid_123',
        'Parser should return transactionHid from event data.'
    );
    test_assert(
        ( $payload['data']['status'] ?? null ) === 'completed',
        'Parser should return status from event data.'
    );
}

function test_parser_rejects_invalid_signature(): void {
    $secret    = 'whsec_test_secret';
    $timestamp = (string) time();
    $raw_body  = wp_json_encode( [
        'id'   => 'evt_test_456',
        'type' => 'checkout_session.failed',
        'data' => [
            'id'      => 'sess_456',
            'orderId' => 'wc_1002',
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

function test_parser_rejects_stale_signature_timestamp(): void {
    $secret    = 'whsec_test_secret';
    $timestamp = (string) ( time() - 301 );
    $raw_body  = wp_json_encode( [
        'id'   => 'evt_test_stale',
        'type' => 'checkout_session.completed',
        'data' => [
            'id'      => 'sess_123',
            'orderId' => 'wc_1003',
        ],
    ] );

    test_assert( is_string( $raw_body ), 'Webhook body should encode to JSON.' );

    $signature = hash_hmac( 'sha256', $timestamp . '.' . $raw_body, $secret );
    $parser    = new OEN_Webhook_Parser( $secret );

    try {
        $parser->parse( $raw_body, 't=' . $timestamp . ',v1=' . $signature );
        throw new RuntimeException( 'Parser should reject stale webhook timestamps.' );
    } catch ( RuntimeException $exception ) {
        test_assert(
            'Expired webhook signature timestamp' === $exception->getMessage(),
            'Parser should reject stale webhook timestamps.'
        );
        test_assert(
            403 === $exception->getCode(),
            'Stale signatures should return HTTP 403 semantics.'
        );
    }
}

function test_handler_treats_missing_session_id_as_stale_when_order_has_stored_session(): void {
    $reason = OEN_Webhook_Handler::detect_attempt_mismatch(
        'sess_current',
        '',
        [
            'transactionHid' => 'txn_hid_123',
        ]
    );

    test_assert(
        is_string( $reason ) && str_contains( $reason, 'missing sessionId' ),
        'Missing sessionId should be treated as stale when the order has a stored session ID.'
    );
}

function test_handler_allows_session_only_verified_attempt_when_session_matches(): void {
    $reason = OEN_Webhook_Handler::detect_attempt_mismatch(
        'sess_current',
        'txn_current',
        [
            'sessionId' => 'sess_current',
        ]
    );

    test_assert(
        null === $reason,
        'Session-based verification should still be usable when the verified payload matches the current session even without transactionHid.'
    );
}

function test_handler_maps_hosted_checkout_event_names_and_statuses(): void {
    $success_resolution = OEN_Webhook_Handler::resolve_event_action( 'checkout_session.completed', 'completed' );
    $failure_resolution = OEN_Webhook_Handler::resolve_event_action( 'checkout_session.cancelled', 'cancelled' );
    $ignored_resolution = OEN_Webhook_Handler::resolve_event_action( 'checkout_session.failed', 'completed' );

    test_assert(
        'success' === $success_resolution,
        'Completed Hosted Checkout events with completed verified status should resolve to success.'
    );
    test_assert(
        'failure' === $failure_resolution,
        'Cancelled Hosted Checkout events with cancelled verified status should resolve to failure.'
    );
    test_assert(
        'ignore' === $ignored_resolution,
        'Failure events with non-failure verified statuses should be ignored.'
    );
}

test_parser_verifies_signature_and_returns_event_type_and_nested_event_data();
test_parser_rejects_invalid_signature();
test_parser_rejects_stale_signature_timestamp();
test_handler_treats_missing_session_id_as_stale_when_order_has_stored_session();
test_handler_allows_session_only_verified_attempt_when_session_matches();
test_handler_maps_hosted_checkout_event_names_and_statuses();

echo "Webhook parser smoke harness passed.\n";
