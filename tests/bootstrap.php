<?php

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

class TestWpError {
    private string $code;
    private string $message;
    private mixed $data;

    public function __construct( string $message, string $code = '', mixed $data = null ) {
        $this->message = $message;
        $this->code    = $code;
        $this->data    = $data;
    }

    public function get_error_message(): string {
        return $this->message;
    }

    public function get_error_code(): string {
        return $this->code;
    }

    public function get_error_data(): mixed {
        return $this->data;
    }
}

if ( ! class_exists( 'WP_Error', false ) ) {
    class_alias( TestWpError::class, 'WP_Error' );
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
    return $value instanceof TestWpError || $value instanceof WP_Error;
}

function get_option( string $option, mixed $default = false ): mixed {
    return $GLOBALS['test_options'][ $option ] ?? $default;
}

function wp_remote_retrieve_response_code( mixed $response ): int {
    if ( is_wp_error( $response ) ) {
        return 0;
    }
    return (int) ( $response['response']['code'] ?? 0 );
}

function wp_remote_retrieve_body( mixed $response ): string {
    if ( is_wp_error( $response ) ) {
        return '';
    }
    return (string) ( $response['body'] ?? '' );
}

function wp_remote_post( string $url, array $args = [] ): array|TestWpError {
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

function wp_remote_get( string $url, array $args = [] ): array|TestWpError {
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
