<?php

/**
 * Register a Hosted Checkout webhook that points at the PUBLIC tunnel URL, and
 * store its signing secret — so the real backend can reach this local bench.
 *
 * The plugin's built-in auto-registration derives the URL from home_url() (i.e.
 * http://localhost:8080), which the backend cannot reach; this helper registers
 * the tunnel URL instead.
 *
 * Run inside the bench:
 *   wp eval-file wp-content/plugins/woocommerce-oen-payment/tests/e2e/register-webhook.php "<tunnel>/?wc-api=oen_payment"
 */

$url = $args[0] ?? '';

if ( '' === $url ) {
    fwrite( STDERR, "usage: register-webhook.php <public_webhook_url>\n" );
    exit( 1 );
}

$events = [
    'checkout_session.completed',
    'checkout_session.failed',
    'checkout_session.expired',
    'checkout_session.cancelled',
    'refund.created',
    'refund.succeeded',
];

try {
    $client   = OEN_API_Client::from_settings();
    $resource = $client->create_webhook( $url, $events );
} catch ( \Throwable $e ) {
    fwrite( STDERR, 'webhook registration failed: ' . $e->getMessage() . "\n" );
    exit( 1 );
}

$id     = (string) ( $resource['id'] ?? '' );
$secret = (string) ( $resource['secret'] ?? '' );

if ( '' === $id || '' === $secret ) {
    fwrite( STDERR, "backend did not return webhook id + secret (secret is only returned on create/rotate)\n" );
    exit( 1 );
}

update_option( 'oen_webhook_id', $id );
update_option( 'oen_webhook_secret', $secret );

echo wp_json_encode( [
    'webhook_id'     => $id,
    'secret_stored'  => true,
    'url'            => $url,
    'enabled_events' => $events,
] ) . "\n";
