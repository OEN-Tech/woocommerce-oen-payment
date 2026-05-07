<?php

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

$request_uri = $_SERVER['REQUEST_URI'] ?? '/';
$path        = (string) parse_url( $request_uri, PHP_URL_PATH );

if ( '/health' === $path ) {
    header( 'Content-Type: application/json' );
    echo json_encode( [ 'status' => 'ok' ] );
    return;
}

$query_string = (string) parse_url( $request_uri, PHP_URL_QUERY );
parse_str( $query_string, $query_params );

$test_case = (string) ( $query_params['case'] ?? '' );

if ( '' === $test_case ) {
    http_response_code( 400 );
    echo json_encode( [ 'error' => 'Missing case' ] );
    return;
}

function __( string $text, string $domain = '' ): string {
    return $text;
}

function add_action( string $hook, array $callback ): void {}

function sanitize_text_field( mixed $value ): string {
    if ( is_scalar( $value ) ) {
        return trim( strip_tags( (string) $value ) );
    }

    return '';
}

function get_option( string $name, mixed $default = false ): mixed {
    if ( 'oen_webhook_secret' === $name && str_starts_with( (string) ( $GLOBALS['test_webhook_case'] ?? '' ), 'signed_' ) ) {
        return 'whsec_integration_secret';
    }

    return $default;
}

function current_time( string $type = 'mysql' ): string {
    return '2026-04-05T00:00:00+00:00';
}

function wc_get_logger(): object {
    return new class() {
        public function info( string $message, array $context = [] ): void {}

        public function debug( string $message, array $context = [] ): void {}
    };
}

final class WC_Order {
    private int $id;
    private int $total;
    private bool $paid = false;
    private string $status = 'pending';
    private array $meta = [];
    private array $notes = [];

    public function __construct( int $id, int $total ) {
        $this->id    = $id;
        $this->total = $total;
    }

    public function get_id(): int {
        return $this->id;
    }

    public function get_total(): int {
        return $this->total;
    }

    public function get_meta( string $key ): mixed {
        return $this->meta[ $key ] ?? '';
    }

    public function update_meta_data( string $key, mixed $value ): void {
        $this->meta[ $key ] = $value;
    }

    public function save(): void {}

    public function is_paid(): bool {
        return $this->paid;
    }

    public function payment_complete( string $transaction_hid = '' ): void {
        $this->paid   = true;
        $this->status = 'processing';
        if ( '' !== $transaction_hid ) {
            $this->meta['_oen_transaction_hid'] = $transaction_hid;
        }
    }

    public function add_order_note( string $note ): void {
        $this->notes[] = $note;
    }

    public function update_status( string $status, string $note = '' ): void {
        $this->status = $status;
        if ( '' !== $note ) {
            $this->notes[] = $note;
        }
    }

    public function export_state(): array {
        return [
            'id'     => $this->id,
            'paid'   => $this->paid,
            'status' => $this->status,
            'meta'   => $this->meta,
            'notes'  => $this->notes,
        ];
    }
}

$GLOBALS['test_webhook_case']  = $test_case;
$GLOBALS['test_order_id']      = 2001;
$GLOBALS['test_order_lookup']  = 'wc-order-2001';
$GLOBALS['test_order_session'] = match ( $test_case ) {
    'ambiguous_completed' => 'sess_ambiguous',
    'signed_ambiguous_completed' => 'sess_ambiguous',
    'missing_amount' => 'sess_missing_amount',
    default => 'sess_default',
};
$GLOBALS['test_order']         = new WC_Order( $GLOBALS['test_order_id'], 1234 );
$GLOBALS['test_order']->update_meta_data( '_oen_order_id', $GLOBALS['test_order_lookup'] );
$GLOBALS['test_order']->update_meta_data( '_oen_session_id', $GLOBALS['test_order_session'] );

function wc_get_orders( array $args ): array {
    if ( ( $args['meta_key'] ?? '' ) === '_oen_order_id'
        && ( $args['meta_value'] ?? '' ) === ( $GLOBALS['test_order_lookup'] ?? '' ) ) {
        return [ $GLOBALS['test_order'] ];
    }

    return [];
}

function wc_get_order( int $order_id ): ?WC_Order {
    if ( $order_id === ( $GLOBALS['test_order_id'] ?? 0 ) ) {
        return $GLOBALS['test_order'];
    }

    return null;
}

function wp_send_json( array $data, int $status_code = 200 ): void {
    http_response_code( $status_code );
    header( 'Content-Type: application/json' );
    echo json_encode( [
        'payload' => $data,
        'order'   => $GLOBALS['test_order']->export_state(),
    ] );
    exit;
}

final class IntegrationWpdb {
    public string $prefix = 'wp_';

    public function prepare( string $query, mixed ...$args ): string {
        return $query;
    }

    public function get_var( string $query ): string {
        return '1';
    }

    public function query( string $query ): int {
        return 1;
    }
}

$GLOBALS['wpdb'] = new IntegrationWpdb();

require_once __DIR__ . '/../includes/class-oen-webhook-parser.php';

if ( ! class_exists( 'OEN_API_Client', false ) ) {
    final class OEN_API_Client {
        public static function from_settings(): self {
            return new self();
        }

        public function get_session( string $session_id ): array {
            return match ( $GLOBALS['test_webhook_case'] ?? '' ) {
                'ambiguous_completed' => [
                    'id'      => $session_id,
                    'orderId' => $GLOBALS['test_order_lookup'],
                    'status'  => 'completed',
                    'amount'  => 1234,
                ],
                'signed_ambiguous_completed' => [
                    'id'      => $session_id,
                    'orderId' => $GLOBALS['test_order_lookup'],
                    'status'  => 'completed',
                    'amount'  => 1234,
                ],
                'missing_amount' => [
                    'id'          => $session_id,
                    'orderId'     => $GLOBALS['test_order_lookup'],
                    'transaction' => [
                        'status' => 'charged',
                    ],
                ],
                default => [],
            };
        }

        public function get_transaction( string $transaction_hid ): array {
            return [];
        }
    }
}

require_once __DIR__ . '/../includes/class-oen-webhook-handler.php';

$handler = new OEN_Webhook_Handler();
$handler->handle();
