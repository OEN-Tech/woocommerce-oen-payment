<?php

defined( 'ABSPATH' ) || exit;

class OEN_API_Client {

    private const PRODUCTION_API_URL = 'https://payment-api.oen.tw';
    private const SANDBOX_API_URL    = 'https://payment-api.testing.oen.tw';

    private const PRODUCTION_CHECKOUT_HOST = 'oen.tw';
    private const SANDBOX_CHECKOUT_HOST    = 'testing.oen.tw';

    private string $merchant_id;
    private string $api_token;
    private bool   $sandbox;
    private string $base_url;

    public function __construct( string $merchant_id, string $api_token, bool $sandbox = false ) {
        $this->merchant_id = $merchant_id;
        $this->api_token   = $api_token;
        $this->sandbox     = $sandbox;
        $this->base_url    = $sandbox ? self::SANDBOX_API_URL : self::PRODUCTION_API_URL;
    }

    public function create_checkout( array $params ): array {
        $params['merchantId'] = $this->merchant_id;
        if ( ! isset( $params['currency'] ) ) {
            $params['currency'] = 'TWD';
        }
        $response = wp_remote_post(
            $this->base_url . '/checkout',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->api_token,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => wp_json_encode( $params ),
                'timeout' => 30,
            ]
        );
        return $this->parse_response( $response );
    }

    public function get_transaction( string $transaction_id ): array {
        $response = wp_remote_get(
            $this->base_url . '/transactions/' . urlencode( $transaction_id ),
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->api_token,
                ],
                'timeout' => 15,
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
        $api_token   = get_option( 'oen_api_token', '' );
        $sandbox     = 'yes' === get_option( 'oen_sandbox', 'no' );
        if ( empty( $merchant_id ) || empty( $api_token ) ) {
            throw new \RuntimeException(
                __( 'OEN Payment is not configured. Please set MerchantID and API Token.', 'woocommerce-oen-payment' )
            );
        }
        return new self( $merchant_id, $api_token, $sandbox );
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
        if ( $status_code < 200 || $status_code >= 300 ) {
            throw new \RuntimeException(
                sprintf(
                    __( 'OEN Payment API returned HTTP %1$d: %2$s', 'woocommerce-oen-payment' ),
                    $status_code,
                    $body['message'] ?? 'Unknown error'
                )
            );
        }
        if ( ! is_array( $body ) || ( $body['code'] ?? '' ) !== 'S0000' ) {
            $code    = $body['code'] ?? 'UNKNOWN';
            $message = $body['message'] ?? 'Unknown error';
            throw new \RuntimeException(
                sprintf(
                    __( 'OEN Payment API error [%1$s]: %2$s', 'woocommerce-oen-payment' ),
                    $code,
                    $message
                )
            );
        }
        return $body['data'] ?? [];
    }
}
