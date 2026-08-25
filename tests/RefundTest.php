<?php

declare( strict_types=1 );

/**
 * Locks the merchant-initiated refund path.
 *
 * Before this existed the gateway declared supports = ['products'] and had no
 * process_refund() of its own, so WooCommerce inherited the abstract stub
 * (WC_Payment_Gateway::process_refund() returns false) and never rendered an
 * automatic refund button. The only button a merchant could press was "Refund
 * manually", which marks the order refunded in WooCommerce while OEN keeps the
 * charge — the money is never returned.
 *
 * What must hold:
 *  - the card gateway advertises 'refunds'; CVS does not (the backend has no
 *    asynchronous CVS refund, so a button there could only fail);
 *  - a successful refund POSTs to /sessions/{id}/refunds and returns true;
 *  - every failure path returns WP_Error, because WooCommerce only creates the
 *    WC_Order_Refund when process_refund() returns true;
 *  - the OEN refund id is claimed before returning true, so the refund.succeeded
 *    webhook for the same refund does not mirror it a second time; and
 *  - an in-progress claim is taken BEFORE the refund request goes out, because the
 *    backend emits refund.succeeded while it is still answering that request — the
 *    webhook can arrive before the refund id is known here. Without the claim a
 *    partial refund gets mirrored twice and total_refunded doubles.
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'OEN_PAYMENT_PLUGIN_URL' ) ) {
    define( 'OEN_PAYMENT_PLUGIN_URL', 'https://store.example/wp-content/plugins/woocommerce-oen-payment/' );
}
if ( ! defined( 'OEN_PAYMENT_VERSION' ) ) {
    define( 'OEN_PAYMENT_VERSION', 'test' );
}

if ( ! function_exists( 'add_action' ) ) {
    function add_action( string $hook, mixed $callback, int $priority = 10, int $args = 1 ): void {}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( string $value ): string {
        return trim( strip_tags( $value ) );
    }
}
if ( ! function_exists( 'wc_get_checkout_url' ) ) {
    function wc_get_checkout_url(): string {
        return 'https://store.example/checkout';
    }
}
if ( ! function_exists( 'wc_get_cart_url' ) ) {
    function wc_get_cart_url(): string {
        return 'https://store.example/cart';
    }
}
if ( ! function_exists( 'get_bloginfo' ) ) {
    function get_bloginfo( string $show = '' ): string {
        return 'Test Store';
    }
}

if ( ! class_exists( 'WC_Payment_Gateway', false ) ) {
    class WC_Payment_Gateway {
        public string $id = '';
        public string $method_title = '';
        public string $method_description = '';
        public string $icon = '';
        public string $title = '';
        public string $description = '';
        public string $enabled = '';
        public bool $has_fields = false;
        public array $supports = [];
        public array $form_fields = [];

        public function init_settings(): void {}

        public function get_option( string $key, mixed $default = '' ): mixed {
            return $default;
        }

        public function get_return_url( mixed $order = null ): string {
            return 'https://store.example/thank-you';
        }

        public function process_admin_options(): void {}

        public function supports( string $feature ): bool {
            return in_array( $feature, $this->supports, true );
        }
    }
}

class Test_Refund_Order {
    /** @var array<string, mixed> */
    private array $meta;

    /** @var array<int, string> */
    public array $notes = [];

    public int $save_calls = 0;

    /**
     * @param array<string, mixed> $meta
     */
    public function __construct( private int $id, array $meta = [] ) {
        $this->meta = $meta;
    }

    public function get_id(): int {
        return $this->id;
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
        $this->save_calls++;
        return $this->id;
    }

    public function add_order_note( string $note ): int {
        $this->notes[] = $note;
        return count( $this->notes );
    }
}

if ( ! class_exists( 'WC_Order', false ) ) {
    class_alias( Test_Refund_Order::class, 'WC_Order' );
}

$GLOBALS['test_current_order'] = null;

if ( ! function_exists( 'wc_get_order' ) ) {
    function wc_get_order( mixed $order_id ): mixed {
        return $GLOBALS['test_current_order'];
    }
}

require_once dirname( __DIR__ ) . '/includes/class-oen-api-client.php';
require_once dirname( __DIR__ ) . '/includes/class-oen-refund-registry.php';
require_once dirname( __DIR__ ) . '/includes/class-wc-gateway-oen.php';
require_once dirname( __DIR__ ) . '/includes/class-wc-gateway-oen-credit.php';
require_once dirname( __DIR__ ) . '/includes/class-wc-gateway-oen-cvs.php';

$GLOBALS['test_options'] = [
    'oen_merchant_id' => 'test-merchant',
    'oen_api_token'   => 'sk_test_harness',
    'oen_sandbox'     => 'yes',
];

function refund_reset_http(): void {
    $GLOBALS['test_http_post_calls'] = [];
    $GLOBALS['test_http_post_queue'] = [];
}

/**
 * @param array<string, mixed> $body
 */
function refund_queue_response( int $status, array $body ): void {
    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => $status ],
        'body'     => wp_json_encode( $body ),
    ];
}

$credit = new WC_Gateway_OEN_Credit();
$cvs    = new WC_Gateway_OEN_CVS();

// ---- supports ----------------------------------------------------------------

test_assert(
    $credit->supports( 'refunds' ),
    'the card gateway must advertise refunds, otherwise WooCommerce renders no automatic '
        . 'refund button and the only option left falsifies the order'
);
test_assert(
    ! $cvs->supports( 'refunds' ),
    'the CVS gateway must NOT advertise refunds — the backend has no asynchronous CVS refund'
);

// ---- happy path --------------------------------------------------------------

refund_reset_http();
refund_queue_response(
    200,
    [
        'id'        => 'rf_harness_1',
        'sessionId' => 'cs_harness_1',
        'amount'    => 100,
        'status'    => 'refunded',
    ]
);

$order = new Test_Refund_Order( 4242, [ '_oen_session_id' => 'cs_harness_1' ] );
$GLOBALS['test_current_order'] = $order;

$result = $credit->process_refund( 4242, 100.0, 'customer changed their mind' );

test_assert( true === $result, 'a confirmed refund must return true so WooCommerce records it' );
test_assert( 1 === count( $GLOBALS['test_http_post_calls'] ), 'exactly one refund request must be sent' );

$call = $GLOBALS['test_http_post_calls'][0];
test_assert(
    str_ends_with( $call['url'], '/hosted-checkout/v1/sessions/cs_harness_1/refunds' ),
    'the refund must be addressed by session id, not transaction hid. Got: ' . $call['url']
);
test_assert(
    isset( $call['args']['headers']['Idempotency-Key'] ) && '' !== $call['args']['headers']['Idempotency-Key'],
    'the refund request must carry an Idempotency-Key'
);

$sent = json_decode( (string) $call['args']['body'], true );
test_assert( 100 === ( $sent['amount'] ?? null ), 'the amount must be sent as an integer' );
test_assert(
    'customer changed their mind' === ( $sent['reason'] ?? null ),
    'the merchant reason must be forwarded'
);

test_assert(
    OEN_Refund_Registry::is_processed( $order, 'rf_harness_1' ),
    'the OEN refund id must be claimed before returning true, or the refund.succeeded '
        . 'webhook will mirror the same refund again and double total_refunded'
);
test_assert( 1 === count( $order->notes ), 'a single order note must record the refund' );
test_assert(
    ! OEN_Refund_Registry::is_in_progress( $order ),
    'the in-progress claim must be released once the refund id is recorded'
);

// ---- API rejects the refund --------------------------------------------------

refund_reset_http();
refund_queue_response(
    400,
    [
        'error' => [
            'code'    => 'SESSION_INVALID_STATE',
            'message' => 'charge already refunded',
        ],
    ]
);

$order = new Test_Refund_Order( 4243, [ '_oen_session_id' => 'cs_harness_2' ] );
$GLOBALS['test_current_order'] = $order;

$result = $credit->process_refund( 4243, 100.0 );

test_assert(
    is_wp_error( $result ),
    'a rejected refund must return WP_Error — returning anything else lets WooCommerce mark '
        . 'the order refunded while OEN still holds the money'
);
test_assert(
    [] === $order->get_meta( OEN_Refund_Registry::META_KEY ) || '' === $order->get_meta( OEN_Refund_Registry::META_KEY ),
    'a failed refund must not claim any refund id'
);
test_assert(
    ! OEN_Refund_Registry::is_in_progress( $order ),
    'a failed refund must release the in-progress claim, or webhook mirroring stays blocked'
);

// ---- API answers 200 but not terminal ----------------------------------------

refund_reset_http();
refund_queue_response( 200, [ 'id' => 'rf_harness_3', 'status' => 'pending' ] );

$order = new Test_Refund_Order( 4244, [ '_oen_session_id' => 'cs_harness_3' ] );
$GLOBALS['test_current_order'] = $order;

test_assert(
    is_wp_error( $credit->process_refund( 4244, 100.0 ) ),
    'a non-terminal refund status must return WP_Error'
);
test_assert(
    ! OEN_Refund_Registry::is_in_progress( $order ),
    'a non-terminal refund must release the in-progress claim'
);

// ---- order without a session id ----------------------------------------------

refund_reset_http();
$order = new Test_Refund_Order( 4245 );
$GLOBALS['test_current_order'] = $order;

test_assert(
    is_wp_error( $credit->process_refund( 4245, 100.0 ) ),
    'an order with no OEN session id must return WP_Error'
);
test_assert(
    [] === $GLOBALS['test_http_post_calls'],
    'no request may be sent when the order carries no session id'
);

// ---- non-positive amount -----------------------------------------------------

refund_reset_http();
$order = new Test_Refund_Order( 4246, [ '_oen_session_id' => 'cs_harness_6' ] );
$GLOBALS['test_current_order'] = $order;

test_assert(
    is_wp_error( $credit->process_refund( 4246, 0.0 ) ),
    'a zero refund amount must return WP_Error'
);
test_assert( [] === $GLOBALS['test_http_post_calls'], 'no request may be sent for a zero amount' );

// ---- CVS gateway refuses -----------------------------------------------------

refund_reset_http();
$order = new Test_Refund_Order( 4247, [ '_oen_session_id' => 'cs_harness_7' ] );
$GLOBALS['test_current_order'] = $order;

test_assert(
    is_wp_error( $cvs->process_refund( 4247, 100.0 ) ),
    'the CVS gateway must refuse refunds instead of calling the API'
);
test_assert( [] === $GLOBALS['test_http_post_calls'], 'the CVS gateway must not send a refund request' );

// ---- registry idempotency ----------------------------------------------------

$order = new Test_Refund_Order( 4248 );
test_assert( ! OEN_Refund_Registry::is_processed( $order, 'rf_x' ), 'unknown refund ids are not processed' );
OEN_Refund_Registry::mark_processed( $order, 'rf_x' );
test_assert( OEN_Refund_Registry::is_processed( $order, 'rf_x' ), 'a marked refund id reads back as processed' );
OEN_Refund_Registry::mark_processed( $order, 'rf_x' );
test_assert(
    [ 'rf_x' ] === $order->get_meta( OEN_Refund_Registry::META_KEY ),
    'marking the same refund id twice must not duplicate it'
);
test_assert( ! OEN_Refund_Registry::is_processed( $order, '' ), 'an empty refund id is never processed' );

// ---- in-progress claim (the webhook race) ------------------------------------

$order = new Test_Refund_Order( 4249 );
test_assert( ! OEN_Refund_Registry::is_in_progress( $order ), 'a fresh order has no claim' );

OEN_Refund_Registry::begin( $order );
test_assert(
    OEN_Refund_Registry::is_in_progress( $order ),
    'begin() must mark the order so an early refund.succeeded webhook defers to the admin path'
);

OEN_Refund_Registry::end( $order );
test_assert( ! OEN_Refund_Registry::is_in_progress( $order ), 'end() must release the claim' );

OEN_Refund_Registry::begin( $order );
OEN_Refund_Registry::mark_processed( $order, 'rf_race' );
test_assert(
    ! OEN_Refund_Registry::is_in_progress( $order ),
    'recording a refund id must also release the claim'
);
test_assert( OEN_Refund_Registry::is_processed( $order, 'rf_race' ), 'the refund id must be recorded' );

// A claim from a request that died must not block webhook mirroring forever.
$order = new Test_Refund_Order( 4250 );
$order->update_meta_data(
    OEN_Refund_Registry::IN_PROGRESS_META_KEY,
    (string) ( time() - OEN_Refund_Registry::IN_PROGRESS_TTL - 1 )
);
test_assert(
    ! OEN_Refund_Registry::is_in_progress( $order ),
    'a stale claim must expire so the webhook can mirror refunds again'
);

echo "Refund harness passed.\n";
