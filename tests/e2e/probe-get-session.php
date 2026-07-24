<?php

/**
 * GET a Hosted Checkout session through the plugin's api-client, to verify the
 * plugin reads the REAL session shape (top-level status/amount/orderId/etc.) that
 * the webhook handler's verify_session() relies on.
 *
 *   wp eval-file .../tests/e2e/probe-get-session.php <session_id>
 */

$session_id = $args[0] ?? '';

try {
    $client  = OEN_API_Client::from_settings();
    $session = $client->get_session( $session_id );
    echo wp_json_encode( [ 'ok' => true, 'session' => $session ], JSON_PRETTY_PRINT ) . "\n";
} catch ( \Throwable $e ) {
    echo wp_json_encode( [ 'ok' => false, 'error' => $e->getMessage() ] ) . "\n";
}
