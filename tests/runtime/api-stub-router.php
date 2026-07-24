<?php

declare( strict_types=1 );

/**
 * Real Hosted Checkout API stub for the L2 runtime harness.
 *
 * Serves the SAME shapes as the merged backend (develop/qa), verified against
 * source (OC-6900):
 *   - 2xx success: the raw resource object — NO legacy `{ code: 'S0000', data }` wrapper.
 *   - GET session: the lifecycle `status` at the TOP LEVEL
 *     (created/completed/failed/expired/cancelled); there is NO nested
 *     `transaction` object. CVS sessions carry a flat top-level `paymentInfo`.
 *   - errors: `{ error: { code, message }, requestId }` with the matching HTTP status.
 *
 * This replaced an earlier stub that still encoded the old S0000 / nested
 * `transaction.status` contract (a shape the backend never returns), which would
 * validate the plugin against a fiction. Keep this stub in lock-step with the
 * backend contract, not with the plugin's internal fallbacks.
 */

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = (string) parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );

header( 'Content-Type: application/json' );

$send = static function ( int $status, array $body ): void {
    http_response_code( $status );
    echo json_encode( $body );
};

$error = static function ( int $status, string $code, string $message ) use ( $send ): void {
    $send(
        $status,
        [
            'error'     => [ 'code' => $code, 'message' => $message ],
            'requestId' => 'req_stub_' . bin2hex( random_bytes( 6 ) ),
        ]
    );
};

if ( '/health' === $path ) {
    $send( 200, [ 'status' => 'ok' ] );
    return;
}

// Create session: POST /hosted-checkout/v1/sessions -> minimal raw resource (HTTP 200).
if ( 'POST' === $method && '/hosted-checkout/v1/sessions' === $path ) {
    $send(
        200,
        [
            'id'          => 'cs_stub_' . bin2hex( random_bytes( 6 ) ),
            'checkoutUrl' => 'http://api-stub:8080/checkout/stub',
            'status'      => 'created',
            'expiresAt'   => gmdate( 'c', time() + 1800 ),
        ]
    );
    return;
}

// Get session: GET /hosted-checkout/v1/sessions/{id} -> raw resource, top-level status.
if ( 'GET' === $method && 1 === preg_match( '#^/hosted-checkout/v1/sessions/([^/]+)$#', $path, $matches ) ) {
    $session_id = urldecode( $matches[1] );

    $sessions = [
        // Completed + matching amount/order -> the order must be paid.
        // transactionId/Hid are returned at the TOP LEVEL (no nested transaction).
        'sess_runtime_signed_success'        => [
            'id'             => 'sess_runtime_signed_success',
            'status'         => 'completed',
            'amount'         => 1234,
            'currency'       => 'TWD',
            'orderId'        => 'wc-runtime-signed-success',
            'transactionId'  => 'txn_runtime_success_internal',
            'transactionHid' => 'txn_runtime_success_001',
            'expiresAt'      => gmdate( 'c', time() + 1800 ),
        ],

        // Authoritative status is still pending -> a completed webhook must be
        // IGNORED. The plugin trusts the GET-session status over the webhook claim.
        'sess_runtime_signed_ambiguous'      => [
            'id'       => 'sess_runtime_signed_ambiguous',
            'status'   => 'pending',
            'amount'   => 1234,
            'currency' => 'TWD',
            'orderId'  => 'wc-runtime-signed-ambiguous',
        ],

        // No amount field at all -> verification must fail closed (502).
        'sess_runtime_signed_missing_amount' => [
            'id'      => 'sess_runtime_signed_missing_amount',
            'status'  => 'completed',
            'orderId' => 'wc-runtime-signed-missing-amount',
        ],

        // CVS awaiting payment: paymentInfo present but session not yet completed
        // -> the completed webhook is ignored and no CVS meta is stored.
        'sess_runtime_cvs_pending'           => [
            'id'          => 'sess_runtime_cvs_pending',
            'status'      => 'pending',
            'amount'      => 1234,
            'currency'    => 'TWD',
            'orderId'     => 'wc-runtime-cvs-pending',
            'paymentInfo' => [
                'method'    => 'cvs',
                'code'      => 'CVS1234567890',
                'cvsName'   => 'FamilyMart',
                'expiredAt' => '2026-04-13T23:59:59+08:00',
            ],
        ],

        // CVS paid: completed + top-level paymentInfo -> order paid AND CVS meta persisted.
        'sess_runtime_cvs_completed'         => [
            'id'             => 'sess_runtime_cvs_completed',
            'status'         => 'completed',
            'amount'         => 1234,
            'currency'       => 'TWD',
            'orderId'        => 'wc-runtime-cvs-completed',
            'transactionId'  => 'txn_runtime_cvs_internal',
            'transactionHid' => 'txn_runtime_cvs_001',
            'paymentInfo'    => [
                'method'    => 'cvs',
                'code'      => 'CVS1234567890',
                'cvsName'   => 'FamilyMart',
                'expiredAt' => '2026-04-13T23:59:59+08:00',
            ],
        ],
    ];

    if ( isset( $sessions[ $session_id ] ) ) {
        $send( 200, $sessions[ $session_id ] );
        return;
    }

    $error( 404, 'SESSION_NOT_FOUND', 'No such session' );
    return;
}

$error( 404, 'INVALID_REQUEST', 'Not found' );
