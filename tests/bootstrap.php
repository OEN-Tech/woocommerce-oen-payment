<?php

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

class TestWpError {
    private string $message;

    public function __construct( string $message ) {
        $this->message = $message;
    }

    public function get_error_message(): string {
        return $this->message;
    }
}

$GLOBALS['test_options']           = $GLOBALS['test_options'] ?? [];
$GLOBALS['test_http_post_calls']   = $GLOBALS['test_http_post_calls'] ?? [];
$GLOBALS['test_http_get_calls']    = $GLOBALS['test_http_get_calls'] ?? [];
$GLOBALS['test_http_post_queue']    = $GLOBALS['test_http_post_queue'] ?? [];
$GLOBALS['test_http_get_queue']     = $GLOBALS['test_http_get_queue'] ?? [];

function __( string $text, string $domain = '' ): string {
    return $text;
}

function is_wp_error( mixed $value ): bool {
    return $value instanceof TestWpError;
}

function get_option( string $option, mixed $default = false ): mixed {
    return $GLOBALS['test_options'][ $option ] ?? $default;
}

function wp_remote_retrieve_response_code( array $response ): int {
    return (int) ( $response['response']['code'] ?? 0 );
}

function wp_remote_retrieve_body( array $response ): string {
    return (string) ( $response['body'] ?? '' );
}

function wp_remote_post( string $url, array $args = [] ): array {
    $GLOBALS['test_http_post_calls'][] = [
        'url'  => $url,
        'args' => $args,
    ];

    return array_shift( $GLOBALS['test_http_post_queue'] ) ?? [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
            'code' => 'S0000',
            'data' => [],
        ] ),
    ];
}

function wp_remote_get( string $url, array $args = [] ): array {
    $GLOBALS['test_http_get_calls'][] = [
        'url'  => $url,
        'args' => $args,
    ];

    return array_shift( $GLOBALS['test_http_get_queue'] ) ?? [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
            'code' => 'S0000',
            'data' => [],
        ] ),
    ];
}

function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
    return json_encode( $value, $flags, $depth );
}

function test_assert( bool $condition, string $message ): void {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}
