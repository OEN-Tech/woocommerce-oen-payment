<?php

declare( strict_types=1 );

require_once __DIR__ . '/bootstrap.php';

function integration_find_available_port(): int {
    for ( $attempt = 0; $attempt < 20; $attempt++ ) {
        $port = random_int( 20000, 40000 );
        $socket = @stream_socket_server( 'tcp://127.0.0.1:' . $port, $errno, $errstr );

        if ( false !== $socket ) {
            fclose( $socket );
            return $port;
        }
    }

    throw new RuntimeException( 'Unable to find an available port for webhook integration test.' );
}

function integration_wait_for_server( int $port ): void {
    $deadline = microtime( true ) + 5;

    while ( microtime( true ) < $deadline ) {
        $response = @file_get_contents( 'http://127.0.0.1:' . $port . '/health' );
        if ( false !== $response ) {
            return;
        }

        usleep( 100000 );
    }

    throw new RuntimeException( 'Timed out waiting for webhook test server to boot.' );
}

function integration_start_server(): array {
    $port       = integration_find_available_port();
    $router     = __DIR__ . '/webhook-handler-router.php';
    $stdout_log = tempnam( sys_get_temp_dir(), 'oen-webhook-out-' );
    $stderr_log = tempnam( sys_get_temp_dir(), 'oen-webhook-err-' );

    $command = sprintf(
        'php -S 127.0.0.1:%d %s',
        $port,
        escapeshellarg( $router )
    );

    $descriptors = [
        0 => [ 'pipe', 'r' ],
        1 => [ 'file', $stdout_log, 'w' ],
        2 => [ 'file', $stderr_log, 'w' ],
    ];

    $process = proc_open( $command, $descriptors, $pipes, dirname( __DIR__ ) );

    if ( ! is_resource( $process ) ) {
        throw new RuntimeException( 'Failed to start webhook integration server.' );
    }

    if ( isset( $pipes[0] ) && is_resource( $pipes[0] ) ) {
        fclose( $pipes[0] );
    }

    try {
        integration_wait_for_server( $port );
    } catch ( Throwable $exception ) {
        proc_terminate( $process );
        proc_close( $process );
        throw $exception;
    }

    return [
        'port'       => $port,
        'process'    => $process,
        'stdout_log' => $stdout_log,
        'stderr_log' => $stderr_log,
    ];
}

function integration_stop_server( array $server ): void {
    if ( isset( $server['process'] ) && is_resource( $server['process'] ) ) {
        proc_terminate( $server['process'] );
        proc_close( $server['process'] );
    }

    foreach ( [ 'stdout_log', 'stderr_log' ] as $key ) {
        $path = $server[ $key ] ?? '';
        if ( is_string( $path ) && '' !== $path && file_exists( $path ) ) {
            unlink( $path );
        }
    }
}

function integration_post_webhook( int $port, string $case, array $payload, array $headers = [] ): array {
    $header_lines = [ 'Content-Type: application/json' ];

    foreach ( $headers as $name => $value ) {
        $header_lines[] = $name . ': ' . $value;
    }

    $context = stream_context_create( [
        'http' => [
            'method'        => 'POST',
            'ignore_errors' => true,
            'header'        => implode( "\r\n", $header_lines ) . "\r\n",
            'content'       => wp_json_encode( $payload ),
            'timeout'       => 5,
        ],
    ] );

    $response = file_get_contents(
        'http://127.0.0.1:' . $port . '/?case=' . rawurlencode( $case ),
        false,
        $context
    );

    test_assert( false !== $response, 'Webhook integration request should return a response.' );

    $headers = $http_response_header ?? [];
    $status_line = $headers[0] ?? '';
    preg_match( '/\s(\d{3})\s/', $status_line, $matches );
    $status_code = isset( $matches[1] ) ? intval( $matches[1] ) : 0;
    $decoded = json_decode( (string) $response, true );

    test_assert(
        is_array( $decoded ),
        'Webhook integration response should decode as JSON. Raw response: ' . (string) $response
    );

    return [
        'status_code' => $status_code,
        'body'        => $decoded,
    ];
}

function integration_build_signature_header( string $secret, array $payload, ?int $timestamp = null ): string {
    $timestamp = $timestamp ?? time();
    $raw_body  = wp_json_encode( $payload );

    test_assert( is_string( $raw_body ), 'Webhook payload should encode to JSON for signature calculation.' );

    $signature = hash_hmac( 'sha256', $timestamp . '.' . $raw_body, $secret );

    return 't=' . $timestamp . ',v1=' . $signature;
}

function test_handle_marks_order_paid_for_completed_session(): void {
    $server = integration_start_server();

    try {
        // The verification API (integration router) returns a session whose
        // top-level status is `completed` with a matching orderId and amount.
        // That is the authoritative success signal — the order must be paid.
        $result = integration_post_webhook(
            $server['port'],
            'ambiguous_completed',
            [
                'type' => 'checkout_session.completed',
                'data' => [
                    'id'      => 'sess_ambiguous',
                    'orderId' => 'wc-order-2001',
                    'status'  => 'completed',
                ],
            ]
        );

        test_assert(
            200 === $result['status_code'],
            'A verified completed session should return HTTP 200.'
        );
        test_assert(
            ( $result['body']['payload']['status'] ?? null ) === 'ok',
            'A verified completed session should be accepted, not ignored.'
        );
        test_assert(
            true === ( $result['body']['order']['paid'] ?? false ),
            'Order must be marked paid when a verified completed session matches the order.'
        );
        test_assert(
            '' !== ( $result['body']['order']['meta']['_oen_paid_at'] ?? '' ),
            'Order should record paid_at when a completed session is verified.'
        );
    } finally {
        integration_stop_server( $server );
    }
}

function test_handle_fails_closed_when_verified_session_amount_is_missing(): void {
    $server = integration_start_server();

    try {
        $result = integration_post_webhook(
            $server['port'],
            'missing_amount',
            [
                'type' => 'checkout_session.completed',
                'data' => [
                    'id'      => 'sess_missing_amount',
                    'orderId' => 'wc-order-2001',
                    'status'  => 'completed',
                ],
            ]
        );

        test_assert(
            502 === $result['status_code'],
            'Verified sessions with missing amount should fail closed with a verification error.'
        );
        test_assert(
            ( $result['body']['payload']['message'] ?? null ) === 'Verification failed',
            'Missing amount should surface the existing verification failure response.'
        );
        test_assert(
            false === ( $result['body']['order']['paid'] ?? true ),
            'Order must remain unpaid when verified session amount is missing.'
        );
        test_assert(
            '' === ( $result['body']['order']['meta']['_oen_paid_at'] ?? '' ),
            'Order should not record paid_at when verification fails.'
        );
    } finally {
        integration_stop_server( $server );
    }
}

function test_handle_marks_order_paid_for_valid_signed_completed_session(): void {
    $server  = integration_start_server();
    $payload = [
        'type' => 'checkout_session.completed',
        'data' => [
            'id'      => 'sess_ambiguous',
            'orderId' => 'wc-order-2001',
            'status'  => 'completed',
        ],
    ];

    try {
        $result = integration_post_webhook(
            $server['port'],
            'signed_ambiguous_completed',
            $payload,
            [
                'OenPay-Signature' => integration_build_signature_header( 'whsec_integration_secret', $payload ),
            ]
        );

        test_assert(
            200 === $result['status_code'],
            'A validly signed, verified completed session should return HTTP 200.'
        );
        test_assert(
            ( $result['body']['payload']['status'] ?? null ) === 'ok',
            'A validly signed, verified completed session should be accepted.'
        );
        test_assert(
            true === ( $result['body']['order']['paid'] ?? false ),
            'Order must be marked paid for a validly signed, verified completed session.'
        );
    } finally {
        integration_stop_server( $server );
    }
}

function test_handle_rejects_invalid_signature_before_processing_webhook(): void {
    $server  = integration_start_server();
    $payload = [
        'type' => 'checkout_session.completed',
        'data' => [
            'id'      => 'sess_ambiguous',
            'orderId' => 'wc-order-2001',
            'status'  => 'completed',
        ],
    ];

    try {
        $result = integration_post_webhook(
            $server['port'],
            'signed_ambiguous_completed',
            $payload,
            [
                'OenPay-Signature' => 't=' . time() . ',v1=invalidsignature',
            ]
        );

        test_assert(
            403 === $result['status_code'],
            'Invalid signatures should be rejected before webhook processing.'
        );
        test_assert(
            ( $result['body']['payload']['message'] ?? null ) === 'Invalid webhook signature',
            'Invalid signatures should surface the parser rejection message.'
        );
        test_assert(
            false === ( $result['body']['order']['paid'] ?? true ),
            'Order must remain unpaid when signature verification fails.'
        );
    } finally {
        integration_stop_server( $server );
    }
}

function test_handle_refund_succeeded_creates_wc_refund(): void {
    $server = integration_start_server();

    try {
        $payload = [
            'type' => 'refund.succeeded',
            'data' => [
                'id'        => 'rf_success_1',
                'sessionId' => 'cs_refund_test',
                'amount'    => 500,
                'status'    => 'refunded',
                'reason'    => 'customer request',
                'mode'      => 'test',
                'createdAt' => '2026-04-05T00:00:00+00:00',
            ],
        ];
        $result = integration_post_webhook(
            $server['port'],
            'refund_succeeded',
            $payload,
            [ 'OenPay-Signature' => integration_build_signature_header( 'whsec_integration_secret', $payload ) ]
        );

        test_assert(
            200 === $result['status_code'],
            'A refund.succeeded event should return HTTP 200.'
        );
        test_assert(
            1 === count( $result['body']['refunds'] ?? [] ),
            'refund.succeeded should create exactly one WooCommerce refund.'
        );
        test_assert(
            500 === ( $result['body']['refunds'][0]['amount'] ?? null )
                && 2001 === ( $result['body']['refunds'][0]['order_id'] ?? null ),
            'The WooCommerce refund should use the event amount and the session-correlated order id.'
        );
    } finally {
        integration_stop_server( $server );
    }
}

function test_handle_refund_created_is_acknowledged_without_refunding(): void {
    $server = integration_start_server();

    try {
        $payload = [
            'type' => 'refund.created',
            'data' => [
                'id'        => 'rf_created_1',
                'sessionId' => 'cs_refund_test',
                'amount'    => 500,
                'status'    => 'refunded',
                'mode'      => 'test',
                'createdAt' => '2026-04-05T00:00:00+00:00',
            ],
        ];
        $result = integration_post_webhook(
            $server['port'],
            'refund_created',
            $payload,
            [ 'OenPay-Signature' => integration_build_signature_header( 'whsec_integration_secret', $payload ) ]
        );

        test_assert(
            200 === $result['status_code'],
            'A refund.created event should be acknowledged with HTTP 200.'
        );
        test_assert(
            0 === count( $result['body']['refunds'] ?? [ 'sentinel' ] ),
            'refund.created must not create a WooCommerce refund (refund.succeeded does).'
        );
    } finally {
        integration_stop_server( $server );
    }
}

function test_handle_refund_succeeded_is_idempotent(): void {
    $server = integration_start_server();

    try {
        $payload = [
            'type' => 'refund.succeeded',
            'data' => [
                'id'        => 'rf_dup',
                'sessionId' => 'cs_refund_test',
                'amount'    => 500,
                'status'    => 'refunded',
                'mode'      => 'test',
                'createdAt' => '2026-04-05T00:00:00+00:00',
            ],
        ];
        $result = integration_post_webhook(
            $server['port'],
            'refund_already_processed',
            $payload,
            [ 'OenPay-Signature' => integration_build_signature_header( 'whsec_integration_secret', $payload ) ]
        );

        test_assert(
            200 === $result['status_code'],
            'A duplicate refund.succeeded should return HTTP 200.'
        );
        test_assert(
            ( $result['body']['payload']['message'] ?? null ) === 'Refund already processed',
            'An already-processed refund id should be recognized as idempotent.'
        );
        test_assert(
            0 === count( $result['body']['refunds'] ?? [ 'sentinel' ] ),
            'An already-processed refund must not create a second WooCommerce refund.'
        );
    } finally {
        integration_stop_server( $server );
    }
}

function test_handle_refund_for_unknown_session_returns_404(): void {
    $server = integration_start_server();

    try {
        $payload = [
            'type' => 'refund.succeeded',
            'data' => [
                'id'        => 'rf_orphan',
                'sessionId' => 'cs_does_not_match',
                'amount'    => 500,
                'status'    => 'refunded',
                'mode'      => 'test',
                'createdAt' => '2026-04-05T00:00:00+00:00',
            ],
        ];
        $result = integration_post_webhook(
            $server['port'],
            'refund_succeeded',
            $payload,
            [ 'OenPay-Signature' => integration_build_signature_header( 'whsec_integration_secret', $payload ) ]
        );

        test_assert(
            404 === $result['status_code'],
            'A refund for an unknown session id should return HTTP 404.'
        );
        test_assert(
            0 === count( $result['body']['refunds'] ?? [ 'sentinel' ] ),
            'No WooCommerce refund should be created for an unknown session.'
        );
    } finally {
        integration_stop_server( $server );
    }
}

function test_handle_refund_creation_failure_returns_502(): void {
    $server = integration_start_server();

    try {
        $payload = [
            'type' => 'refund.succeeded',
            'data' => [
                'id'        => 'rf_failed_1',
                'sessionId' => 'cs_refund_test',
                'amount'    => 500,
                'status'    => 'refunded',
                'mode'      => 'test',
                'createdAt' => '2026-04-05T00:00:00+00:00',
            ],
        ];
        $result = integration_post_webhook(
            $server['port'],
            'refund_fail',
            $payload,
            [ 'OenPay-Signature' => integration_build_signature_header( 'whsec_integration_secret', $payload ) ]
        );

        test_assert(
            502 === $result['status_code'],
            'A failed WooCommerce refund creation should return HTTP 502.'
        );
        test_assert(
            ( $result['body']['payload']['message'] ?? null ) === 'Refund creation failed',
            'A failed refund should report a creation failure.'
        );
        test_assert(
            ! in_array( 'rf_failed_1', (array) ( $result['body']['order']['meta']['_oen_processed_refund_ids'] ?? [] ), true ),
            'A failed refund must not be marked processed, so a retry can succeed.'
        );
    } finally {
        integration_stop_server( $server );
    }
}

function test_handle_refund_without_configured_secret_is_rejected(): void {
    $server = integration_start_server();

    try {
        // No webhook secret configured for this case, so signature verification is
        // skipped — a refund event must fail closed rather than act on an unsigned,
        // potentially forged payload.
        $result = integration_post_webhook(
            $server['port'],
            'refund_unsigned',
            [
                'type' => 'refund.succeeded',
                'data' => [
                    'id'        => 'rf_unsigned_1',
                    'sessionId' => 'cs_refund_test',
                    'amount'    => 500,
                    'status'    => 'refunded',
                    'mode'      => 'test',
                    'createdAt' => '2026-04-05T00:00:00+00:00',
                ],
            ]
        );

        test_assert(
            401 === $result['status_code'],
            'A refund event must be rejected when no webhook secret is configured.'
        );
        test_assert(
            0 === count( $result['body']['refunds'] ?? [ 'sentinel' ] ),
            'No WooCommerce refund may be created from an unsigned refund event.'
        );
    } finally {
        integration_stop_server( $server );
    }
}

test_handle_marks_order_paid_for_completed_session();
test_handle_fails_closed_when_verified_session_amount_is_missing();
test_handle_marks_order_paid_for_valid_signed_completed_session();
test_handle_rejects_invalid_signature_before_processing_webhook();
test_handle_refund_succeeded_creates_wc_refund();
test_handle_refund_created_is_acknowledged_without_refunding();
test_handle_refund_succeeded_is_idempotent();
test_handle_refund_for_unknown_session_returns_404();
test_handle_refund_creation_failure_returns_502();
test_handle_refund_without_configured_secret_is_rejected();

echo "Webhook handler integration harness passed.\n";
