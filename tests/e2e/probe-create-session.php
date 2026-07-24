<?php

/**
 * Probe the plugin's OWN api-client create_session against the configured backend.
 *
 * With an invalid key this verifies — end to end, through the plugin — that:
 *   - from_settings() reads the configured merchant/secret + OEN_API_BASE_URL,
 *   - the request reaches the real /api/hosted-checkout/v1/sessions route,
 *   - parse_response() correctly decodes the real `{error:{code,message}}` envelope.
 * Expect a thrown RuntimeException carrying [AUTH_INVALID_KEY] (HTTP 401) on dev/qa.
 *
 *   wp eval-file wp-content/plugins/woocommerce-oen-payment/tests/e2e/probe-create-session.php
 */

try {
    $client = OEN_API_Client::from_settings();
    $result = $client->create_session( [
        'amount'         => 100,
        'currency'       => 'TWD',
        'orderId'        => 'probe-' . time(),
        'productDetails' => [
            [ 'productionCode' => 'P', 'description' => 'probe', 'quantity' => 1, 'unit' => 'set', 'unitPrice' => 100 ],
        ],
    ] );
    echo wp_json_encode( [ 'result' => 'UNEXPECTED_SUCCESS', 'session' => $result ] ) . "\n";
} catch ( \Throwable $e ) {
    echo wp_json_encode( [
        'result'       => 'threw_as_expected',
        'message'      => $e->getMessage(),
        'base_url_env' => getenv( 'OEN_API_BASE_URL' ) ?: '(unset)',
    ] ) . "\n";
}
