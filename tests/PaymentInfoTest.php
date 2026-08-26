<?php

declare( strict_types=1 );

/**
 * Locks the handling of off-site payment codes (CVS).
 *
 * The buyer picks convenience-store payment on the OEN hosted page, sees a code
 * there, and never returns to the store. The code is created after the redirect, so
 * it cannot be known at process_payment() time, and no webhook announces it — the
 * API emits checkout_session.completed/failed/expired/cancelled and refund.* only.
 *
 * Previously the plugin wrote _oen_cvs_* solely inside handle_success(), so an order
 * still awaiting payment — the one case where the buyer needs the code — never had
 * it, and the plugin had no surface that displayed it at all.
 *
 * What must hold:
 *  - apply() accepts the paymentInfo shape from both GET /sessions and webhooks;
 *  - is_pending() identifies exactly the orders that should be polled;
 *  - the sync fetches, stores, and stops — it must not poll forever, must not poll
 *    an order that already has a code, and must give up when the session reaches a
 *    state that can never produce one.
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'OEN_PAYMENT_PLUGIN_URL' ) ) {
    define( 'OEN_PAYMENT_PLUGIN_URL', 'https://store.example/wp-content/plugins/woocommerce-oen-payment/' );
}

$GLOBALS['test_scheduled'] = [];

if ( ! function_exists( 'add_action' ) ) {
    function add_action( string $hook, mixed $callback, int $priority = 10, int $args = 1 ): void {}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( string $value ): string {
        return trim( strip_tags( $value ) );
    }
}
if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( string $value ): string {
        return htmlspecialchars( $value, ENT_QUOTES );
    }
}
if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( string $text, string $domain = '' ): string {
        return $text;
    }
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
    function wp_schedule_single_event( int $timestamp, string $hook, array $args = [] ): bool {
        $GLOBALS['test_scheduled'][] = [ 'timestamp' => $timestamp, 'hook' => $hook, 'args' => $args ];
        return true;
    }
}

class Test_Payment_Info_Order {
    /** @var array<string, mixed> */
    private array $meta;

    /** @var array<int, string> */
    public array $notes = [];

    public function __construct(
        private int $id,
        private string $payment_method,
        private string $status,
        array $meta = []
    ) {
        $this->meta = $meta;
    }

    public function get_id(): int {
        return $this->id;
    }

    public function get_payment_method(): string {
        return $this->payment_method;
    }

    public function get_status(): string {
        return $this->status;
    }

    public function get_meta( string $key, bool $single = true ): mixed {
        return $this->meta[ $key ] ?? '';
    }

    public function update_meta_data( string $key, mixed $value ): void {
        $this->meta[ $key ] = $value;
    }

    public function delete_meta_data( string $key ): void {
        unset( $this->meta[ $key ] );
    }

    public function save(): int {
        return $this->id;
    }

    public function add_order_note( string $note ): int {
        $this->notes[] = $note;
        return count( $this->notes );
    }
}

if ( ! class_exists( 'WC_Order', false ) ) {
    class_alias( Test_Payment_Info_Order::class, 'WC_Order' );
}

$GLOBALS['test_current_order'] = null;

if ( ! function_exists( 'wc_get_order' ) ) {
    function wc_get_order( mixed $order_id ): mixed {
        return $GLOBALS['test_current_order'];
    }
}

require_once dirname( __DIR__ ) . '/includes/class-oen-api-client.php';
require_once dirname( __DIR__ ) . '/includes/class-oen-payment-info.php';
require_once dirname( __DIR__ ) . '/includes/class-oen-payment-info-sync.php';

$GLOBALS['test_options'] = [
    'oen_merchant_id' => 'test-merchant',
    'oen_api_token'   => 'sk_test_harness',
    'oen_sandbox'     => 'yes',
];

const CVS_PAYLOAD = [
    'method'      => 'cvs',
    'code'        => 'ABC123456789',
    'cvsCode'     => 'ABC123456789',
    'cvsName'     => 'Convenience store',
    'expiredAt'   => '2026-08-27T08:43:01.522Z',
    'totalAmount' => 100,
];

function pi_reset(): void {
    $GLOBALS['test_http_get_calls'] = [];
    $GLOBALS['test_http_get_queue'] = [];
    $GLOBALS['test_scheduled']      = [];
}

/**
 * @param array<string, mixed> $body
 */
function pi_queue_session( array $body, int $status = 200 ): void {
    $GLOBALS['test_http_get_queue'][] = [
        'response' => [ 'code' => $status ],
        'body'     => wp_json_encode( $body ),
    ];
}

// ---- apply() -----------------------------------------------------------------

$order = new Test_Payment_Info_Order( 1, 'oen_cvs', 'on-hold' );

test_assert( OEN_Payment_Info::apply( $order, CVS_PAYLOAD ), 'a fresh payload must report a change' );
test_assert( 'ABC123456789' === $order->get_meta( OEN_Payment_Info::META_CODE ), 'the code must be stored' );
test_assert( 'Convenience store' === $order->get_meta( OEN_Payment_Info::META_NAME ), 'the store name must be stored' );
test_assert(
    '2026-08-27T08:43:01.522Z' === $order->get_meta( OEN_Payment_Info::META_EXPIRES ),
    'the deadline must be stored'
);
test_assert( ! OEN_Payment_Info::apply( $order, CVS_PAYLOAD ), 're-applying the same payload must report no change' );
test_assert( ! OEN_Payment_Info::apply( $order, [] ), 'an empty payload must report no change' );
test_assert(
    ! OEN_Payment_Info::apply( $order, [ 'code' => [ 'nested' ] ] ),
    'a non-scalar value must be ignored rather than stored'
);

// ---- get() -------------------------------------------------------------------

test_assert(
    [] === OEN_Payment_Info::get( new Test_Payment_Info_Order( 2, 'oen_cvs', 'on-hold' ) ),
    'an order with no code must report no payment info'
);
$info = OEN_Payment_Info::get( $order );
test_assert( 'ABC123456789' === ( $info['code'] ?? '' ), 'get() must return the stored code' );

// ---- is_pending() ------------------------------------------------------------

test_assert(
    OEN_Payment_Info::is_pending( new Test_Payment_Info_Order( 3, 'oen_cvs', 'on-hold' ) ),
    'an on-hold CVS order without a code is exactly what needs fetching'
);
test_assert(
    ! OEN_Payment_Info::is_pending( new Test_Payment_Info_Order( 4, 'oen_credit', 'on-hold' ) ),
    'a card order never produces a payment code'
);
test_assert(
    ! OEN_Payment_Info::is_pending( new Test_Payment_Info_Order( 5, 'oen_cvs', 'processing' ) ),
    'a paid order must not be polled'
);
test_assert(
    ! OEN_Payment_Info::is_pending(
        new Test_Payment_Info_Order( 6, 'oen_cvs', 'on-hold', [ OEN_Payment_Info::META_CODE => 'X' ] )
    ),
    'an order that already has a code must not be polled again'
);

// ---- sync: stores the code and stops -----------------------------------------

$sync = new OEN_Payment_Info_Sync();

pi_reset();
pi_queue_session( [ 'id' => 'cs_1', 'status' => 'created', 'paymentInfo' => CVS_PAYLOAD ] );

$order = new Test_Payment_Info_Order( 10, 'oen_cvs', 'on-hold', [ '_oen_session_id' => 'cs_1' ] );
$GLOBALS['test_current_order'] = $order;

$sync->run( 10, 0 );

test_assert( 1 === count( $GLOBALS['test_http_get_calls'] ), 'exactly one session read per attempt' );
test_assert(
    str_ends_with( $GLOBALS['test_http_get_calls'][0]['url'], '/hosted-checkout/v1/sessions/cs_1' ),
    'the sync must read the session recorded on the order'
);
test_assert( 'ABC123456789' === $order->get_meta( OEN_Payment_Info::META_CODE ), 'the fetched code must be stored' );
test_assert( 1 === count( $order->notes ), 'storing the code must leave an order note' );
test_assert( [] === $GLOBALS['test_scheduled'], 'a successful fetch must not queue another attempt' );

// ---- sync: retries while the code does not exist yet -------------------------

pi_reset();
pi_queue_session( [ 'id' => 'cs_2', 'status' => 'created' ] );

$order = new Test_Payment_Info_Order( 11, 'oen_cvs', 'on-hold', [ '_oen_session_id' => 'cs_2' ] );
$GLOBALS['test_current_order'] = $order;

$sync->run( 11, 0 );

test_assert( '' === $order->get_meta( OEN_Payment_Info::META_CODE ), 'nothing may be stored when there is no code' );
test_assert( 1 === count( $GLOBALS['test_scheduled'] ), 'a missing code must queue the next attempt' );
test_assert(
    [ 11, 1 ] === $GLOBALS['test_scheduled'][0]['args'],
    'the retry must carry the incremented attempt number'
);

// ---- sync: gives up on a terminal session ------------------------------------

pi_reset();
pi_queue_session( [ 'id' => 'cs_3', 'status' => 'expired' ] );

$order = new Test_Payment_Info_Order( 12, 'oen_cvs', 'on-hold', [ '_oen_session_id' => 'cs_3' ] );
$GLOBALS['test_current_order'] = $order;

$sync->run( 12, 0 );

test_assert(
    [] === $GLOBALS['test_scheduled'],
    'an expired session can never produce a code — polling must stop'
);

// ---- sync: the schedule is bounded -------------------------------------------

pi_reset();
pi_queue_session( [ 'id' => 'cs_4', 'status' => 'created' ] );

$order = new Test_Payment_Info_Order( 13, 'oen_cvs', 'on-hold', [ '_oen_session_id' => 'cs_4' ] );
$GLOBALS['test_current_order'] = $order;

$sync->run( 13, 99 );

test_assert(
    [] === $GLOBALS['test_scheduled'],
    'the retry schedule must be finite — a stuck order must not be polled forever'
);

// ---- sync: refuses orders it has no business polling --------------------------

pi_reset();
$GLOBALS['test_current_order'] = new Test_Payment_Info_Order( 14, 'oen_credit', 'on-hold', [ '_oen_session_id' => 'cs_5' ] );
$sync->run( 14, 0 );
test_assert( [] === $GLOBALS['test_http_get_calls'], 'a card order must not be read at all' );

pi_reset();
$GLOBALS['test_current_order'] = new Test_Payment_Info_Order( 15, 'oen_cvs', 'on-hold' );
$sync->run( 15, 0 );
test_assert( [] === $GLOBALS['test_http_get_calls'], 'an order with no session id must not be read' );

echo "Payment info harness passed.\n";
