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

function integration_post_webhook( int $port, string $case, array $payload ): array {
    $context = stream_context_create( [
        'http' => [
            'method'        => 'POST',
            'ignore_errors' => true,
            'header'        => "Content-Type: application/json\r\n",
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

function test_handle_ignores_completed_session_without_authoritative_transaction_status(): void {
    $server = integration_start_server();

    try {
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
            'Ambiguous completed session should still return HTTP 200 so the event is safely ignored.'
        );
        test_assert(
            ( $result['body']['payload']['message'] ?? null ) === 'Event ignored',
            'Ambiguous completed session should be ignored rather than marked as paid.'
        );
        test_assert(
            false === ( $result['body']['order']['paid'] ?? true ),
            'Order must remain unpaid when transaction.status is missing.'
        );
        test_assert(
            '' === ( $result['body']['order']['meta']['_oen_paid_at'] ?? '' ),
            'Order should not record paid_at for ambiguous completed sessions.'
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

test_handle_ignores_completed_session_without_authoritative_transaction_status();
test_handle_fails_closed_when_verified_session_amount_is_missing();

echo "Webhook handler integration harness passed.\n";
