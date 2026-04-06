<?php

declare( strict_types=1 );

$request_uri = $_SERVER['REQUEST_URI'] ?? '/';
$path        = (string) parse_url( $request_uri, PHP_URL_PATH );

header( 'Content-Type: application/json' );

if ( '/health' === $path ) {
    echo json_encode( [ 'status' => 'ok' ] );
    return;
}

if ( 1 === preg_match( '#^/hosted-checkout/v1/sessions/([^/]+)$#', $path, $matches ) ) {
    $session_id = urldecode( $matches[1] );

    $payload = match ( $session_id ) {
        'sess_runtime_signed_ambiguous' => [
            'code' => 'S0000',
            'data' => [
                'id'      => $session_id,
                'orderId' => 'wc-runtime-signed-ambiguous',
                'status'  => 'completed',
                'amount'  => 1234,
            ],
        ],
        'sess_runtime_signed_success' => [
            'code' => 'S0000',
            'data' => [
                'id'      => $session_id,
                'orderId' => 'wc-runtime-signed-success',
                'status'  => 'pending',
                'amount'  => 1234,
                'transaction' => [
                    'status'         => 'charged',
                    'transactionHid' => 'txn_runtime_success_001',
                    'transactionId'  => 'txn_runtime_success_internal',
                    'amount'         => 1234,
                ],
            ],
        ],
        'sess_runtime_signed_missing_amount' => [
            'code' => 'S0000',
            'data' => [
                'id'          => $session_id,
                'orderId'     => 'wc-runtime-signed-missing-amount',
                'transaction' => [
                    'status' => 'charged',
                ],
            ],
        ],
        'sess_runtime_cvs_pending' => [
            'code' => 'S0000',
            'data' => [
                'id'      => $session_id,
                'orderId' => 'wc-runtime-cvs-pending',
                'status'  => 'completed',
                'amount'  => 1234,
                'transaction' => [
                    'status'         => 'pending',
                    'transactionHid' => 'txn_runtime_cvs_pending_001',
                    'transactionId'  => 'txn_runtime_cvs_pending_internal',
                    'amount'         => 1234,
                    'paymentInfo'    => [
                        'cvsName'    => 'FamilyMart',
                        'code'       => 'CVS1234567890',
                        'expiredAt'  => '2026-04-13T23:59:59+08:00',
                    ],
                ],
            ],
        ],
        default => [
            'code'    => 'E404',
            'message' => 'Session not found',
        ],
    };

    if ( 'S0000' !== ( $payload['code'] ?? '' ) ) {
        http_response_code( 404 );
    }

    echo json_encode( $payload );
    return;
}

http_response_code( 404 );
echo json_encode( [
    'code'    => 'E404',
    'message' => 'Not found',
] );
