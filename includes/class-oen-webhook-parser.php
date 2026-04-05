<?php

defined( 'ABSPATH' ) || exit;

/**
 * Pure PHP parser for OEN hosted checkout webhooks.
 */
class OEN_Webhook_Parser {

    private string $webhook_secret;

    public function __construct( string $webhook_secret = '' ) {
        $this->webhook_secret = $webhook_secret;
    }

    /**
     * Verify the signature header when configured, decode the webhook envelope,
     * and return the nested data payload.
     *
     * @param string $raw_body         Raw webhook request body.
     * @param string $signature_header OenPay-Signature header value.
     * @return array<string, mixed>
     */
    public function parse( string $raw_body, string $signature_header = '' ): array {
        if ( '' === $raw_body ) {
            throw new \InvalidArgumentException( 'Invalid webhook payload: empty body', 400 );
        }

        if ( '' !== $this->webhook_secret ) {
            $this->verify_signature( $raw_body, $signature_header );
        }

        $event = json_decode( $raw_body, true );

        if ( ! is_array( $event ) ) {
            throw new \InvalidArgumentException( 'Invalid webhook payload: malformed JSON', 400 );
        }

        $payload = $event['data'] ?? null;

        if ( ! is_array( $payload ) ) {
            throw new \InvalidArgumentException( 'Invalid webhook payload: missing data envelope', 400 );
        }

        return $payload;
    }

    /**
     * Verify OenPay-Signature against "{timestamp}.{raw_body}".
     *
     * @param string $raw_body         Raw webhook request body.
     * @param string $signature_header OenPay-Signature header value.
     */
    private function verify_signature( string $raw_body, string $signature_header ): void {
        $signature_fields = $this->parse_signature_header( $signature_header );
        $timestamp        = $signature_fields['t'];
        $signatures       = $signature_fields['v1'];
        $signed_payload   = $timestamp . '.' . $raw_body;
        $expected         = hash_hmac( 'sha256', $signed_payload, $this->webhook_secret );

        foreach ( $signatures as $signature ) {
            if ( hash_equals( $expected, $signature ) ) {
                return;
            }
        }

        throw new \RuntimeException( 'Invalid webhook signature', 403 );
    }

    /**
     * Parse OenPay-Signature: t=...,v1=...
     *
     * @param string $signature_header OenPay-Signature header value.
     * @return array{t: string, v1: array<int, string>}
     */
    private function parse_signature_header( string $signature_header ): array {
        if ( '' === trim( $signature_header ) ) {
            throw new \RuntimeException( 'Missing OenPay-Signature header', 403 );
        }

        $timestamp  = '';
        $signatures = [];

        foreach ( explode( ',', $signature_header ) as $part ) {
            $pair = explode( '=', trim( $part ), 2 );

            if ( 2 !== count( $pair ) ) {
                continue;
            }

            $key   = trim( $pair[0] );
            $value = trim( $pair[1] );

            if ( 't' === $key ) {
                $timestamp = $value;
            } elseif ( 'v1' === $key && '' !== $value ) {
                $signatures[] = $value;
            }
        }

        if ( '' === $timestamp || ! ctype_digit( $timestamp ) || [] === $signatures ) {
            throw new \InvalidArgumentException( 'Invalid OenPay-Signature header', 400 );
        }

        return [
            't'  => $timestamp,
            'v1' => $signatures,
        ];
    }
}
