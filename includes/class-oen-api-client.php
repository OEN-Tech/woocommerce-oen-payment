<?php

defined( 'ABSPATH' ) || exit;

class OEN_API_Client {

    // The Hosted Checkout endpoints are served by PublicLambdaProxy, mounted at
    // the `/api` stage path on the api.oen.tw HTTP API gateway. The `/api` prefix
    // is required — without it requests 404 at the gateway.
    private const PRODUCTION_API_URL = 'https://api.oen.tw/api';
    private const SANDBOX_API_URL    = 'https://api.testing.oen.tw/api';

    private const PRODUCTION_CHECKOUT_HOST = 'oen.tw';
    private const SANDBOX_CHECKOUT_HOST    = 'testing.oen.tw';

    private string $merchant_id;
    private string $secret_key;
    private bool   $sandbox;
    private string $base_url;

    public function __construct( string $merchant_id, string $secret_key, bool $sandbox = false ) {
        $this->merchant_id = $merchant_id;
        $this->secret_key  = $secret_key;
        $this->sandbox     = $sandbox;
        $this->base_url    = $this->resolve_base_url( $sandbox );
    }

    public function create_session( array $params ): array {
        if ( ! isset( $params['currency'] ) ) {
            $params['currency'] = 'TWD';
        }
        if ( empty( $params['orderId'] ) ) {
            $params['orderId'] = uniqid( 'wc_', true );
        }

        $response = wp_remote_post(
            $this->base_url . '/hosted-checkout/v1/sessions',
            [
                'headers' => [
                    'Authorization'   => 'Bearer ' . $this->secret_key,
                    'Content-Type'    => 'application/json',
                    'Idempotency-Key' => $this->generate_idempotency_key(),
                ],
                'body'    => wp_json_encode( $params ),
                'timeout' => 30,
            ]
        );
        $data = $this->parse_response( $response );

        if ( empty( $data['id'] ) || ! is_string( $data['id'] ) ) {
            throw new \RuntimeException(
                __( 'OEN Payment API did not return a session id.', 'woocommerce-oen-payment' )
            );
        }

        return $data;
    }

    public function get_transaction( string $transaction_id ): array {
        $response = wp_remote_get(
            $this->base_url . '/transactions/' . urlencode( $transaction_id ),
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->secret_key,
                ],
                'timeout' => 15,
            ]
        );
        return $this->parse_response( $response );
    }

    public function get_session( string $session_id ): array {
        $response = wp_remote_get(
            $this->base_url . '/hosted-checkout/v1/sessions/' . urlencode( $session_id ),
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->secret_key,
                ],
                'timeout' => 15,
            ]
        );

        return $this->parse_response( $response );
    }

    /**
     * Register a Hosted Checkout webhook endpoint. The create response is the only
     * place the signing secret is returned, so callers must persist it.
     *
     * @param string   $url    The merchant webhook URL to register.
     * @param string[] $events The event types to subscribe to.
     * @return array<string, mixed> The raw webhook resource, including `secret`.
     */
    public function create_webhook( string $url, array $events ): array {
        $response = wp_remote_post(
            $this->base_url . '/hosted-checkout/v1/webhooks',
            [
                'headers' => [
                    'Authorization'   => 'Bearer ' . $this->secret_key,
                    'Content-Type'    => 'application/json',
                    'Idempotency-Key' => $this->generate_idempotency_key(),
                ],
                'body'    => wp_json_encode( [
                    'url'           => $url,
                    'enabledEvents' => array_values( $events ),
                ] ),
                'timeout' => 30,
            ]
        );

        return $this->parse_response( $response );
    }

    public function get_checkout_url( string $checkout_id ): string {
        $host = $this->sandbox ? self::SANDBOX_CHECKOUT_HOST : self::PRODUCTION_CHECKOUT_HOST;
        return sprintf( 'https://%s.%s/checkout/%s', $this->merchant_id, $host, $checkout_id );
    }

    public static function from_settings(): self {
        $merchant_id = get_option( 'oen_merchant_id', '' );
        $secret_key  = get_option( 'oen_api_token', '' );
        $sandbox     = 'yes' === get_option( 'oen_sandbox', 'no' );
        if ( empty( $merchant_id ) || empty( $secret_key ) ) {
            throw new \RuntimeException(
                __( 'OEN Payment is not configured. Please set MerchantID and Secret Key.', 'woocommerce-oen-payment' )
            );
        }
        return new self( $merchant_id, $secret_key, $sandbox );
    }

    private function resolve_base_url( bool $sandbox ): string {
        $override = getenv( 'OEN_API_BASE_URL' );
        if ( is_string( $override ) && '' !== trim( $override ) ) {
            return rtrim( trim( $override ), '/' );
        }

        return $sandbox ? self::SANDBOX_API_URL : self::PRODUCTION_API_URL;
    }

    private function generate_idempotency_key(): string {
        if ( function_exists( 'wp_generate_uuid4' ) ) {
            return 'session-attempt-' . wp_generate_uuid4();
        }

        try {
            return 'session-attempt-' . bin2hex( random_bytes( 16 ) );
        } catch ( \Exception $exception ) {
            return uniqid( 'session-attempt-', true );
        }
    }

    private function parse_response( array|\WP_Error $response ): array {
        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException(
                sprintf(
                    __( 'OEN Payment API request failed: %s', 'woocommerce-oen-payment' ),
                    $response->get_error_message()
                )
            );
        }
        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = json_decode( wp_remote_retrieve_body( $response ), true );

        // The Hosted Checkout API returns raw resource objects on success and a
        // Stripe-style `{ error: { code, message }, requestId }` envelope on
        // failure. There is no `code: 'S0000'` / `data` wrapper.
        if ( $status_code < 200 || $status_code >= 300 ) {
            $error   = ( is_array( $body ) && is_array( $body['error'] ?? null ) ) ? $body['error'] : [];
            $code    = (string) ( $error['code'] ?? ( is_array( $body ) ? ( $body['code'] ?? 'UNKNOWN' ) : 'UNKNOWN' ) );
            $message = (string) ( $error['message'] ?? ( is_array( $body ) ? ( $body['message'] ?? '' ) : '' ) );
            if ( '' === $message ) {
                $message = 'Unknown error';
            }
            throw new \RuntimeException(
                sprintf(
                    __( 'OEN Payment API error [%1$s] (HTTP %2$d): %3$s', 'woocommerce-oen-payment' ),
                    $code,
                    $status_code,
                    $message
                )
            );
        }

        if ( ! is_array( $body ) ) {
            throw new \RuntimeException(
                __( 'OEN Payment API returned an unexpected response.', 'woocommerce-oen-payment' )
            );
        }

        return $body;
    }
}
