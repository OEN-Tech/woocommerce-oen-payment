<?php

declare( strict_types=1 );

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-oen-api-client.php';

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( mixed $value ): string {
        if ( is_scalar( $value ) ) {
            return trim( (string) $value );
        }

        return '';
    }
}

if ( ! function_exists( 'wc_add_notice' ) ) {
    function wc_add_notice( string $message, string $type = 'success' ): void {
        $GLOBALS['test_wc_notices'][] = [
            'message' => $message,
            'type'    => $type,
        ];
    }
}

if ( ! function_exists( 'add_action' ) ) {
    function add_action( string $hook, array $callback ): void {}
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
        protected array $settings = [];

        public function init_settings(): void {}

        public function get_option( string $key, mixed $default = '' ): mixed {
            return $this->settings[ $key ] ?? $default;
        }

        public function get_return_url( WC_Order $order ): string {
            return 'https://store.example/orders/' . $order->get_id() . '/thank-you';
        }

        public function is_available(): bool {
            return true;
        }

        public function process_admin_options(): void {}
    }
}

if ( ! class_exists( 'WC_Order', false ) ) {
    class WC_Order {
        private int $id;
        private int $total;
        private bool $paid = false;
        private array $meta = [];

        /**
         * Meta as of the last save(). A real WC_Order only persists meta on save(), and
         * anything another request (a webhook) reads comes from what was persisted, so
         * tests about "the order must already be updated when X happens" read this.
         *
         * @var array<string, mixed>
         */
        private array $saved_meta = [];

        /** @var array<int, string> */
        public array $notes = [];

        public function __construct( int $id, int $total = 1234 ) {
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

        public function delete_meta_data( string $key ): void {
            unset( $this->meta[ $key ] );
        }

        public function save(): void {
            $this->saved_meta = $this->meta;
        }

        public function get_saved_meta( string $key ): mixed {
            return $this->saved_meta[ $key ] ?? '';
        }

        public function add_order_note( string $note ): int {
            $this->notes[] = $note;

            return count( $this->notes );
        }

        public function is_paid(): bool {
            return $this->paid;
        }

        /** @var string */
        public string $status = 'pending';

        public function update_status( string $status, string $note = '' ): void {
            $this->status = $status;
        }

        public function set_paid( bool $paid ): void {
            $this->paid = $paid;
        }

        public function get_billing_first_name(): string {
            return 'Test';
        }

        public function get_billing_last_name(): string {
            return 'Buyer';
        }

        public function get_billing_email(): string {
            return 'buyer@example.com';
        }

        public function get_items(): array {
            return [];
        }

        public function get_shipping_total(): int {
            return 0;
        }

        public function get_item_total( mixed $item, bool $inc_tax = false ): int {
            return 0;
        }
    }
}

if ( ! function_exists( 'wc_get_order' ) ) {
    function wc_get_order( int $order_id ): ?WC_Order {
        return $GLOBALS['test_wc_orders'][ $order_id ] ?? null;
    }
}

require_once __DIR__ . '/../includes/class-wc-gateway-oen.php';

if ( ! class_exists( 'Test_OEN_Gateway', false ) ) {
    class Test_OEN_Gateway extends WC_Gateway_OEN {
        public function __construct() {
            $this->id                 = 'oen_test';
            $this->method_title       = 'OEN Test';
            $this->method_description = 'OEN test gateway';
            $this->payment_method_type = 'card';
            $this->icon               = '';

            parent::__construct();
        }

        protected function build_checkout_params( \WC_Order $order ): array {
            return [
                'amount'     => intval( $order->get_total() ),
                'currency'   => 'TWD',
                'orderId'    => 'wc-order-' . $order->get_id(),
                'successUrl' => $this->get_return_url( $order ),
                'failureUrl' => 'https://store.example/checkout',
                'cancelUrl'  => 'https://store.example/cart',
            ];
        }
    }
}

if ( ! class_exists( 'Test_OEN_CVS_Gateway', false ) ) {
    class Test_OEN_CVS_Gateway extends Test_OEN_Gateway {
        public function __construct() {
            parent::__construct();
            $this->payment_method_type = 'cvs';
        }
    }
}

function test_reset_http_stubs(): void {
    $GLOBALS['test_http_post_calls'] = [];
    $GLOBALS['test_http_get_calls']  = [];
    $GLOBALS['test_http_post_queue'] = [];
    $GLOBALS['test_http_get_queue']   = [];
    $GLOBALS['test_http_post_observer'] = null;
    $GLOBALS['test_wc_notices']      = [];
    $GLOBALS['test_wc_orders']       = [];
    $GLOBALS['test_options']         = [];
}

function test_create_session_uses_hosted_checkout_contract(): void {
    test_reset_http_stubs();

    $client = new OEN_API_Client( 'merchant-123', 'sk_test_secret' );

    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
                'id'          => 'sess_123',
                'checkoutUrl' => 'https://oen.tw/checkout/sess_123',
        ] ),
    ];

    test_assert(
        method_exists( $client, 'create_session' ),
        'OEN_API_Client::create_session() should exist for the Hosted Checkout contract.'
    );

    $result = $client->create_session( [
        'amount'    => 1234,
        'currency'  => 'TWD',
        'cancelUrl' => 'https://store.example/cart',
    ] );

    test_assert(
        ( $GLOBALS['test_http_post_calls'][0]['url'] ?? null ) === 'https://api.oen.tw/api/hosted-checkout/v1/sessions',
        'POST should hit /hosted-checkout/v1/sessions.'
    );
    test_assert(
        ( $GLOBALS['test_http_post_calls'][0]['args']['headers']['Authorization'] ?? null ) === 'Bearer sk_test_secret',
        'POST authorization should use the secret key.'
    );
    test_assert(
        ( $GLOBALS['test_http_post_calls'][0]['args']['headers']['Content-Type'] ?? null ) === 'application/json',
        'POST should send JSON content type.'
    );
    test_assert(
        is_string( $GLOBALS['test_http_post_calls'][0]['args']['headers']['Idempotency-Key'] ?? null )
            && '' !== ( $GLOBALS['test_http_post_calls'][0]['args']['headers']['Idempotency-Key'] ?? '' ),
        'POST should send a non-empty Idempotency-Key header.'
    );
    $decoded_body = json_decode( (string) ( $GLOBALS['test_http_post_calls'][0]['args']['body'] ?? '' ), true );
    test_assert(
        json_last_error() === JSON_ERROR_NONE,
        'POST body should decode as JSON.'
    );
    test_assert(
        isset( $decoded_body['amount'], $decoded_body['orderId'], $decoded_body['currency'] ),
        'POST payload should include amount, orderId, and currency.'
    );
    test_assert(
        ! array_key_exists( 'merchantId', $decoded_body ),
        'POST payload should not auto-inject merchantId for the Hosted Checkout v1 secret-key contract.'
    );
    test_assert(
        ( $decoded_body['cancelUrl'] ?? null ) === 'https://store.example/cart',
        'POST payload should preserve cancelUrl when the gateway provides it.'
    );
    test_assert(
        ( $result['id'] ?? null ) === 'sess_123',
        'create_session() should return a non-empty session id because the gateway depends on it for stale-attempt protection.'
    );
    test_assert(
        ( $result['checkoutUrl'] ?? null ) === 'https://oen.tw/checkout/sess_123',
        'create_session() should return checkoutUrl.'
    );
}

function test_create_session_rejects_missing_session_id(): void {
    test_reset_http_stubs();

    $client = new OEN_API_Client( 'merchant-123', 'sk_test_secret' );

    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
                'checkoutUrl' => 'https://oen.tw/checkout/sess_missing',
        ] ),
    ];

    try {
        $client->create_session( [
            'amount'   => 1234,
            'currency' => 'TWD',
        ] );
        throw new RuntimeException( 'create_session() should reject Hosted Checkout responses that omit session id.' );
    } catch ( RuntimeException $exception ) {
        test_assert(
            'OEN Payment API did not return a session id.' === $exception->getMessage(),
            'Hosted Checkout create responses must include a non-empty session id.'
        );
    }
}

function test_create_session_uses_unique_idempotency_key_per_attempt(): void {
    test_reset_http_stubs();

    $client = new OEN_API_Client( 'merchant-123', 'sk_test_secret' );

    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
                'id'          => 'sess_123',
                'checkoutUrl' => 'https://oen.tw/checkout/sess_123',
        ] ),
    ];
    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
                'id'          => 'sess_456',
                'checkoutUrl' => 'https://oen.tw/checkout/sess_456',
        ] ),
    ];

    $params = [
        'amount'   => 1234,
        'currency' => 'TWD',
        'orderId'  => 'wc-order-1001',
    ];

    $client->create_session( $params );
    $client->create_session( $params );

    $first_key  = $GLOBALS['test_http_post_calls'][0]['args']['headers']['Idempotency-Key'] ?? null;
    $second_key = $GLOBALS['test_http_post_calls'][1]['args']['headers']['Idempotency-Key'] ?? null;

    test_assert(
        is_string( $first_key ) && is_string( $second_key ),
        'Each create_session() attempt should send an Idempotency-Key header.'
    );
    test_assert(
        $first_key !== $second_key,
        'Idempotency-Key should be unique per checkout attempt, even for the same orderId.'
    );
}

function test_get_session_uses_hosted_checkout_contract(): void {
    test_reset_http_stubs();

    $client = new OEN_API_Client( 'merchant-123', 'sk_test_secret' );

    $GLOBALS['test_http_get_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
                'id'     => 'sess_123',
                'status' => 'pending',
        ] ),
    ];

    test_assert(
        method_exists( $client, 'get_session' ),
        'OEN_API_Client::get_session() should exist for the Hosted Checkout contract.'
    );

    $result = $client->get_session( 'sess_123' );

    test_assert(
        ( $GLOBALS['test_http_get_calls'][0]['url'] ?? null ) === 'https://api.oen.tw/api/hosted-checkout/v1/sessions/sess_123',
        'GET should hit /hosted-checkout/v1/sessions/{id}.'
    );
    test_assert(
        ( $GLOBALS['test_http_get_calls'][0]['args']['headers']['Authorization'] ?? null ) === 'Bearer sk_test_secret',
        'GET authorization should use the secret key.'
    );
    test_assert(
        ( $result['status'] ?? null ) === 'pending',
        'get_session() should return session status.'
    );
}

function test_get_session_respects_runtime_api_base_url_override(): void {
    test_reset_http_stubs();

    putenv( 'OEN_API_BASE_URL=http://api-stub:8080' );

    try {
        $client = new OEN_API_Client( 'merchant-123', 'sk_test_secret', true );

        $GLOBALS['test_http_get_queue'][] = [
            'response' => [ 'code' => 200 ],
            'body'     => wp_json_encode( [
                    'id'     => 'sess_123',
                    'status' => 'pending',
            ] ),
        ];

        $result = $client->get_session( 'sess_123' );

        test_assert(
            ( $GLOBALS['test_http_get_calls'][0]['url'] ?? null ) === 'http://api-stub:8080/hosted-checkout/v1/sessions/sess_123',
            'Runtime harness should be able to override the OEN API base URL for full WordPress tests.'
        );
        test_assert(
            ( $result['id'] ?? null ) === 'sess_123',
            'Overridden API base URL should still parse Hosted Checkout session responses.'
        );
    } finally {
        putenv( 'OEN_API_BASE_URL' );
    }
}

function test_process_payment_reuses_existing_reusable_session(): void {
    test_reset_http_stubs();

    $GLOBALS['test_options']['oen_merchant_id'] = 'merchant-123';
    $GLOBALS['test_options']['oen_api_token']   = 'sk_test_secret';

    $order = new WC_Order( 1001, 1234 );
    $order->update_meta_data( '_oen_session_id', 'sess_existing' );
    $order->update_meta_data( '_oen_checkout_url', 'https://oen.tw/checkout/stale_existing' );
    $GLOBALS['test_wc_orders'][1001] = $order;

    $GLOBALS['test_http_get_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
                'id'          => 'sess_existing',
                'status'      => 'pending',
                'orderId'     => 'wc-order-1001',
                'amount'      => 1234,
                'checkoutUrl' => 'https://oen.tw/checkout/sess_existing',
                'transaction' => [
                    'status' => 'pending',
                ],
        ] ),
    ];

    $gateway = new Test_OEN_Gateway();
    $result  = $gateway->process_payment( 1001 );

    test_assert(
        'success' === ( $result['result'] ?? null ),
        'process_payment() should succeed when an existing session is still reusable.'
    );
    test_assert(
        'https://oen.tw/checkout/sess_existing' === ( $result['redirect'] ?? null ),
        'process_payment() should prefer the checkout URL returned by the API over the stored checkout URL.'
    );
    test_assert(
        1 === count( $GLOBALS['test_http_get_calls'] ),
        'process_payment() should verify the stored session before deciding whether to reuse it.'
    );
    test_assert(
        0 === count( $GLOBALS['test_http_post_calls'] ),
        'process_payment() should not create a new session when the current one is still reusable.'
    );
}

function test_process_payment_fails_closed_for_mismatched_reusable_session(): void {
    test_reset_http_stubs();

    $GLOBALS['test_options']['oen_merchant_id'] = 'merchant-123';
    $GLOBALS['test_options']['oen_api_token']   = 'sk_test_secret';

    $order = new WC_Order( 1003, 4321 );
    $order->update_meta_data( '_oen_session_id', 'sess_existing' );
    $order->update_meta_data( '_oen_checkout_url', 'https://oen.tw/checkout/sess_existing' );
    $GLOBALS['test_wc_orders'][1003] = $order;

    $GLOBALS['test_http_get_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
                'id'          => 'sess_existing',
                'status'      => 'pending',
                'orderId'     => 'wc-order-9999',
                'amount'      => 4321,
                'checkoutUrl' => 'https://oen.tw/checkout/sess_existing',
                'transaction' => [
                    'status' => 'pending',
                ],
        ] ),
    ];
    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
                'id'          => 'sess_fresh',
                'checkoutUrl' => 'https://oen.tw/checkout/sess_fresh',
        ] ),
    ];

    $gateway = new Test_OEN_Gateway();
    $result  = $gateway->process_payment( 1003 );

    test_assert(
        'failure' === ( $result['result'] ?? null ),
        'process_payment() should fail closed when the stored non-terminal session is not safe to reuse.'
    );
    test_assert(
        ! isset( $result['redirect'] ),
        'process_payment() should not return a fresh checkout URL when reusable session verification fails.'
    );
    test_assert(
        1 === count( $GLOBALS['test_http_get_calls'] ),
        'process_payment() should inspect the stored session before discarding it.'
    );
    test_assert(
        0 === count( $GLOBALS['test_http_post_calls'] ),
        'process_payment() should not create a new session when the fetched non-terminal session does not match the current order.'
    );
    test_assert(
        'error' === ( $GLOBALS['test_wc_notices'][0]['type'] ?? null ),
        'process_payment() should surface the reusable-session verification failure as an error notice.'
    );
}

function test_process_payment_fails_closed_for_completed_terminal_reusable_session(): void {
    test_reset_http_stubs();

    $GLOBALS['test_options']['oen_merchant_id'] = 'merchant-123';
    $GLOBALS['test_options']['oen_api_token']   = 'sk_test_secret';

    $order = new WC_Order( 1004, 2468 );
    $order->update_meta_data( '_oen_session_id', 'sess_completed' );
    $order->update_meta_data( '_oen_checkout_url', 'https://oen.tw/checkout/sess_completed' );
    $GLOBALS['test_wc_orders'][1004] = $order;

    $GLOBALS['test_http_get_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
                'id'          => 'sess_completed',
                'status'      => 'completed',
                'orderId'     => 'wc-order-1004',
                'amount'      => 2468,
                'checkoutUrl' => 'https://oen.tw/checkout/sess_completed',
        ] ),
    ];

    $gateway = new Test_OEN_Gateway();
    $result  = $gateway->process_payment( 1004 );

    test_assert(
        'failure' === ( $result['result'] ?? null ),
        'process_payment() should fail closed when the stored session is already in a completed terminal state.'
    );
    test_assert(
        0 === count( $GLOBALS['test_http_post_calls'] ),
        'process_payment() should not create a fresh session when the existing session is already completed.'
    );
}

function test_process_payment_reuses_pending_session_with_top_level_status(): void {
    test_reset_http_stubs();

    $GLOBALS['test_options']['oen_merchant_id'] = 'merchant-123';
    $GLOBALS['test_options']['oen_api_token']   = 'sk_test_secret';

    $order = new WC_Order( 1006, 8642 );
    $order->update_meta_data( '_oen_session_id', 'sess_pending' );
    $order->update_meta_data( '_oen_checkout_url', 'https://oen.tw/checkout/sess_pending' );
    $GLOBALS['test_wc_orders'][1006] = $order;

    // Hosted Checkout reports a non-terminal session's lifecycle status at the
    // top level. A pending session that still matches the order should be reused
    // so the customer can finish paying — not rejected.
    $GLOBALS['test_http_get_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
                'id'          => 'sess_pending',
                'status'      => 'pending',
                'orderId'     => 'wc-order-1006',
                'amount'      => 8642,
                'checkoutUrl' => 'https://oen.tw/checkout/sess_pending',
        ] ),
    ];
    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
                'id'          => 'sess_should_not_exist',
                'checkoutUrl' => 'https://oen.tw/checkout/sess_should_not_exist',
        ] ),
    ];

    $gateway = new Test_OEN_Gateway();
    $result  = $gateway->process_payment( 1006 );

    test_assert(
        'success' === ( $result['result'] ?? null ),
        'process_payment() should reuse a matching non-terminal (pending) session instead of failing closed.'
    );
    test_assert(
        'https://oen.tw/checkout/sess_pending' === ( $result['redirect'] ?? null ),
        'process_payment() should redirect to the reused session checkout URL.'
    );
    test_assert(
        0 === count( $GLOBALS['test_http_post_calls'] ),
        'process_payment() should not create a new session when an active session can be reused.'
    );
}

function test_process_payment_fails_closed_for_authoritative_charged_session(): void {
    test_reset_http_stubs();

    $GLOBALS['test_options']['oen_merchant_id'] = 'merchant-123';
    $GLOBALS['test_options']['oen_api_token']   = 'sk_test_secret';

    $order = new WC_Order( 1005, 1357 );
    $order->update_meta_data( '_oen_session_id', 'sess_charged' );
    $order->update_meta_data( '_oen_checkout_url', 'https://oen.tw/checkout/sess_charged' );
    $GLOBALS['test_wc_orders'][1005] = $order;

    // Forward-compat fallback: when a session omits the top-level lifecycle
    // status but carries a terminal-success nested transaction status, the stored
    // session is already charged and must not be reused for a retry.
    $GLOBALS['test_http_get_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
                'id'          => 'sess_charged',
                'orderId'     => 'wc-order-1005',
                'amount'      => 1357,
                'checkoutUrl' => 'https://oen.tw/checkout/sess_charged',
                'transaction' => [
                    'status' => 'charged',
                ],
        ] ),
    ];
    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
                'id'          => 'sess_should_not_exist',
                'checkoutUrl' => 'https://oen.tw/checkout/sess_should_not_exist',
        ] ),
    ];

    $gateway = new Test_OEN_Gateway();
    $result  = $gateway->process_payment( 1005 );

    test_assert(
        'failure' === ( $result['result'] ?? null ),
        'process_payment() should fail closed when session verification returns an authoritative charged status.'
    );
    test_assert(
        ! isset( $result['redirect'] ),
        'process_payment() should not return a fresh checkout URL when the current session is already charged.'
    );
    test_assert(
        1 === count( $GLOBALS['test_http_get_calls'] ),
        'process_payment() should verify the stored charged session before making a retry decision.'
    );
    test_assert(
        0 === count( $GLOBALS['test_http_post_calls'] ),
        'process_payment() should not create a new session when the current session is already charged.'
    );
    test_assert(
        'error' === ( $GLOBALS['test_wc_notices'][0]['type'] ?? null ),
        'process_payment() should surface charged-session retry blocks through the existing error notice path.'
    );
}

function test_process_payment_fails_closed_for_unverified_failure_terminal_session(): void {
    test_reset_http_stubs();

    $GLOBALS['test_options']['oen_merchant_id'] = 'merchant-123';
    $GLOBALS['test_options']['oen_api_token']   = 'sk_test_secret';

    $order = new WC_Order( 1007, 9753 );
    $order->update_meta_data( '_oen_session_id', 'sess_failed' );
    $order->update_meta_data( '_oen_checkout_url', 'https://oen.tw/checkout/sess_failed' );
    $GLOBALS['test_wc_orders'][1007] = $order;

    $GLOBALS['test_http_get_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
                'id'          => 'sess_failed',
                'status'      => 'failed',
                'orderId'     => 'wc-order-9999',
                'amount'      => 9753,
                'checkoutUrl' => 'https://oen.tw/checkout/sess_failed',
                'transaction' => [
                    'status' => 'failed',
                ],
        ] ),
    ];
    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
                'id'          => 'sess_should_not_exist',
                'checkoutUrl' => 'https://oen.tw/checkout/sess_should_not_exist',
        ] ),
    ];

    $gateway = new Test_OEN_Gateway();
    $result  = $gateway->process_payment( 1007 );

    test_assert(
        'failure' === ( $result['result'] ?? null ),
        'process_payment() should fail closed when a failure-terminal session cannot be safely bound to the current order.'
    );
    test_assert(
        0 === count( $GLOBALS['test_http_post_calls'] ),
        'process_payment() should not refresh a failure-terminal session before session/order/amount verification succeeds.'
    );
}

function test_process_payment_refreshes_terminal_session_and_clears_stale_transaction_hid(): void {
    test_reset_http_stubs();

    $GLOBALS['test_options']['oen_merchant_id'] = 'merchant-123';
    $GLOBALS['test_options']['oen_api_token']   = 'sk_test_secret';

    $order = new WC_Order( 1002, 5678 );
    $order->update_meta_data( '_oen_session_id', 'sess_old' );
    $order->update_meta_data( '_oen_checkout_url', 'https://oen.tw/checkout/sess_old' );
    $order->update_meta_data( '_oen_transaction_hid', 'txn_old_attempt' );
    $GLOBALS['test_wc_orders'][1002] = $order;

    $GLOBALS['test_http_get_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
                'id'      => 'sess_old',
                'status'  => 'expired',
                'orderId' => 'wc-order-1002',
                'amount'  => 5678,
                'transaction' => [
                    'status' => 'expired',
                ],
        ] ),
    ];
    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
                'id'          => 'sess_new',
                'checkoutUrl' => 'https://oen.tw/checkout/sess_new',
        ] ),
    ];

    $gateway = new Test_OEN_Gateway();
    $result  = $gateway->process_payment( 1002 );

    test_assert(
        'success' === ( $result['result'] ?? null ),
        'process_payment() should create a new session when the stored session is terminal.'
    );
    test_assert(
        'https://oen.tw/checkout/sess_new' === ( $result['redirect'] ?? null ),
        'process_payment() should redirect to the newly created checkout URL after refreshing a terminal session.'
    );
    test_assert(
        'sess_new' === $order->get_meta( '_oen_session_id' ),
        'process_payment() should replace the stored session ID when creating a new attempt.'
    );
    test_assert(
        'https://oen.tw/checkout/sess_new' === $order->get_meta( '_oen_checkout_url' ),
        'process_payment() should store the new checkout URL for future reuse.'
    );
    test_assert(
        '' === $order->get_meta( '_oen_transaction_hid' ),
        'process_payment() should clear an old transaction hid when the new session create response does not include one.'
    );
}

function test_create_webhook_uses_hosted_checkout_contract(): void {
    test_reset_http_stubs();

    $client = new OEN_API_Client( 'merchant-123', 'sk_test_secret' );

    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
                'id'            => 'whk_123',
                'secret'        => 'whsec_abc',
                'enabledEvents' => [ 'refund.succeeded' ],
        ] ),
    ];

    $result = $client->create_webhook( 'https://store.example/?wc-api=oen_payment', [ 'refund.succeeded' ] );

    test_assert(
        ( $GLOBALS['test_http_post_calls'][0]['url'] ?? null ) === 'https://api.oen.tw/api/hosted-checkout/v1/webhooks',
        'create_webhook should POST to /hosted-checkout/v1/webhooks.'
    );
    test_assert(
        ( $GLOBALS['test_http_post_calls'][0]['args']['headers']['Authorization'] ?? null ) === 'Bearer sk_test_secret',
        'create_webhook should authorize with the secret key.'
    );
    $body = json_decode( (string) ( $GLOBALS['test_http_post_calls'][0]['args']['body'] ?? '' ), true );
    test_assert(
        ( $body['url'] ?? null ) === 'https://store.example/?wc-api=oen_payment'
            && ( $body['enabledEvents'] ?? null ) === [ 'refund.succeeded' ],
        'create_webhook should send the url and enabledEvents.'
    );
    test_assert(
        ( $result['id'] ?? null ) === 'whk_123' && ( $result['secret'] ?? null ) === 'whsec_abc',
        'create_webhook should return the raw webhook resource including the secret.'
    );
}

/*
 * Payment-method changes must invalidate session reuse.
 *
 * A hosted checkout session is created for one payment method and its checkout page
 * is that method's page. The reuse path used to check only the session id, the order
 * id and the amount — all of which still match after the buyer goes back and picks a
 * different method — so it handed back the previous method's checkout URL and the
 * buyer could not get away from the method they had just rejected.
 */
function test_process_payment_creates_new_session_when_payment_method_changed(): void {
    test_reset_http_stubs();

    $GLOBALS['test_options']['oen_merchant_id'] = 'merchant-123';
    $GLOBALS['test_options']['oen_api_token']   = 'sk_test_secret';

    $order = new WC_Order( 1010, 1234 );
    $order->update_meta_data( '_oen_session_id', 'sess_cvs' );
    $order->update_meta_data( '_oen_checkout_url', 'https://oen.tw/checkout/sess_cvs' );
    $order->update_meta_data( '_oen_payment_method', 'cvs' );
    $GLOBALS['test_wc_orders'][1010] = $order;

    $GLOBALS['test_http_get_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
                'id'          => 'sess_cvs',
                'status'      => 'created',
                'orderId'     => 'wc-order-1010',
                'amount'      => 1234,
                'checkoutUrl' => 'https://oen.tw/checkout/sess_cvs',
        ] ),
    ];
    // The replacement session is created first; the superseded one is cancelled after.
    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
                'id'          => 'sess_card',
                'checkoutUrl' => 'https://oen.tw/checkout/sess_card',
        ] ),
    ];
    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [ 'id' => 'sess_cvs', 'status' => 'cancelled' ] ),
    ];

    $gateway = new Test_OEN_Gateway();
    $result  = $gateway->process_payment( 1010 );

    test_assert(
        'success' === ( $result['result'] ?? null ),
        'process_payment() should succeed after the buyer changes payment method.'
    );
    test_assert(
        'https://oen.tw/checkout/sess_card' === ( $result['redirect'] ?? null ),
        'process_payment() should redirect to a session created for the newly chosen payment '
            . 'method, not to the checkout URL of the method the buyer just left.'
    );
    test_assert(
        'sess_card' === $order->get_meta( '_oen_session_id' ),
        'process_payment() should store the new session id when the payment method changed.'
    );
    test_assert(
        'card' === $order->get_meta( '_oen_payment_method' ),
        'process_payment() should record the payment method the new session was created for.'
    );

    $create_call = $GLOBALS['test_http_post_calls'][0] ?? null;
    test_assert(
        is_array( $create_call )
            && ( $create_call['url'] ?? null ) === 'https://api.oen.tw/api/hosted-checkout/v1/sessions',
        'process_payment() should create a fresh session when the payment method changed.'
    );
}

/*
 * The superseded session stays payable until it is cancelled, so a tab left open on
 * the previous payment page could charge the same order twice.
 */
function test_process_payment_cancels_the_superseded_session_after_the_replacement_exists(): void {
    test_reset_http_stubs();

    $GLOBALS['test_options']['oen_merchant_id'] = 'merchant-123';
    $GLOBALS['test_options']['oen_api_token']   = 'sk_test_secret';

    $order = new WC_Order( 1011, 1234 );
    $order->update_meta_data( '_oen_session_id', 'sess_cvs' );
    $order->update_meta_data( '_oen_payment_method', 'cvs' );
    $GLOBALS['test_wc_orders'][1011] = $order;

    $GLOBALS['test_http_get_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
                'id'          => 'sess_cvs',
                'status'      => 'created',
                'orderId'     => 'wc-order-1011',
                'amount'      => 1234,
                'checkoutUrl' => 'https://oen.tw/checkout/sess_cvs',
        ] ),
    ];
    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
                'id'          => 'sess_card',
                'checkoutUrl' => 'https://oen.tw/checkout/sess_card',
        ] ),
    ];
    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [ 'id' => 'sess_cvs', 'status' => 'cancelled' ] ),
    ];

    $gateway = new Test_OEN_Gateway();
    $gateway->process_payment( 1011 );

    $urls = array_map(
        static fn( array $call ): string => (string) ( $call['url'] ?? '' ),
        $GLOBALS['test_http_post_calls']
    );

    test_assert(
        in_array( 'https://api.oen.tw/api/hosted-checkout/v1/sessions/sess_cvs/cancel', $urls, true ),
        'process_payment() should cancel the session it walked away from. Sent: ' . implode( ', ', $urls )
    );

    $create_index = array_search( 'https://api.oen.tw/api/hosted-checkout/v1/sessions', $urls, true );
    $cancel_index = array_search(
        'https://api.oen.tw/api/hosted-checkout/v1/sessions/sess_cvs/cancel',
        $urls,
        true
    );

    test_assert(
        is_int( $create_index ) && is_int( $cancel_index ) && $create_index < $cancel_index,
        'The superseded session must be cancelled only after the order points at its '
            . 'replacement. Cancelling emits a cancellation event for the old session, and an '
            . 'order still pointing at that session would be marked failed while the buyer is '
            . 'paying the new one.'
    );
}

/*
 * The order must already carry the replacement session — persisted, not just assigned —
 * when the superseded session is cancelled. Cancelling emits checkout_session.cancelled
 * for the old session, and the webhook handler recognises that event as a superseded
 * attempt only because the session id stored on the order no longer matches it. Cancel
 * first, and the event marks the order the buyer is now paying as failed.
 *
 * The call-order test above cannot see this: it proves the cancel request follows the
 * create request, and a cancel placed between create_session() and the meta store still
 * satisfies it. This one looks at what the order has persisted at the moment the cancel
 * request goes out.
 */
function test_process_payment_persists_the_replacement_session_before_cancelling_the_old_one(): void {
    test_reset_http_stubs();

    $GLOBALS['test_options']['oen_merchant_id'] = 'merchant-123';
    $GLOBALS['test_options']['oen_api_token']   = 'sk_test_secret';

    $order = new WC_Order( 1015, 1234 );
    $order->update_meta_data( '_oen_session_id', 'sess_cvs' );
    $order->update_meta_data( '_oen_payment_method', 'cvs' );
    $order->save();
    $GLOBALS['test_wc_orders'][1015] = $order;

    $GLOBALS['test_http_get_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
                'id'          => 'sess_cvs',
                'status'      => 'created',
                'orderId'     => 'wc-order-1015',
                'amount'      => 1234,
                'checkoutUrl' => 'https://oen.tw/checkout/sess_cvs',
        ] ),
    ];
    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
                'id'          => 'sess_card',
                'checkoutUrl' => 'https://oen.tw/checkout/sess_card',
        ] ),
    ];
    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [ 'id' => 'sess_cvs', 'status' => 'cancelled' ] ),
    ];

    $persisted_at_cancel = [];

    $GLOBALS['test_http_post_observer'] = static function ( string $url ) use ( $order, &$persisted_at_cancel ): void {
        if ( str_ends_with( $url, '/hosted-checkout/v1/sessions/sess_cvs/cancel' ) ) {
            $persisted_at_cancel[] = [
                'session_id' => $order->get_saved_meta( '_oen_session_id' ),
                'method'     => $order->get_saved_meta( '_oen_payment_method' ),
            ];
        }
    };

    $gateway = new Test_OEN_Gateway();
    $result  = $gateway->process_payment( 1015 );

    test_assert(
        'success' === ( $result['result'] ?? null ),
        'process_payment() should succeed. Got: ' . var_export( $result, true )
    );
    test_assert(
        1 === count( $persisted_at_cancel ),
        'The superseded session must be cancelled exactly once. Cancel requests seen: '
            . count( $persisted_at_cancel )
    );
    test_assert(
        'sess_card' === $persisted_at_cancel[0]['session_id'] && 'card' === $persisted_at_cancel[0]['method'],
        'When the cancel request goes out, the order must already have persisted the '
            . 'replacement session id and payment method. Otherwise the cancellation event for '
            . 'the old session matches the order and marks the attempt the buyer is paying as '
            . 'failed. Persisted at cancel time: ' . var_export( $persisted_at_cancel[0], true )
    );
}

/*
 * Cancelling is best-effort: the buyer is waiting on a redirect and must not be blocked
 * by it. The order is annotated instead, because a session that could not be cancelled
 * is the case where a second payment remains possible.
 */
function test_process_payment_survives_a_failed_supersede_cancellation(): void {
    test_reset_http_stubs();

    $GLOBALS['test_options']['oen_merchant_id'] = 'merchant-123';
    $GLOBALS['test_options']['oen_api_token']   = 'sk_test_secret';

    $order = new WC_Order( 1012, 1234 );
    $order->update_meta_data( '_oen_session_id', 'sess_cvs' );
    $order->update_meta_data( '_oen_payment_method', 'cvs' );
    $GLOBALS['test_wc_orders'][1012] = $order;

    $GLOBALS['test_http_get_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
                'id'          => 'sess_cvs',
                'status'      => 'created',
                'orderId'     => 'wc-order-1012',
                'amount'      => 1234,
                'checkoutUrl' => 'https://oen.tw/checkout/sess_cvs',
        ] ),
    ];
    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
                'id'          => 'sess_card',
                'checkoutUrl' => 'https://oen.tw/checkout/sess_card',
        ] ),
    ];
    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 409 ],
        'body'     => wp_json_encode( [
            'error' => [
                'code'    => 'SESSION_INVALID_STATE',
                'message' => 'session can no longer be cancelled',
            ],
        ] ),
    ];

    $gateway = new Test_OEN_Gateway();
    $result  = $gateway->process_payment( 1012 );

    test_assert(
        'success' === ( $result['result'] ?? null ),
        'A failed cancellation must not fail the payment — the replacement session exists '
            . 'and the buyer must still be redirected to it.'
    );
    test_assert(
        'https://oen.tw/checkout/sess_card' === ( $result['redirect'] ?? null ),
        'The buyer should still be redirected to the new checkout URL.'
    );
    test_assert(
        1 === count( $order->notes ),
        'A session that could not be cancelled must leave an order note, because that is the '
            . 'case where the order can still be paid twice.'
    );
}

/*
 * The reuse path is the point of the session cache: an unchanged payment method must not
 * start a new session on every submit, or every retry would leave an abandoned session
 * behind.
 */
function test_process_payment_reuses_session_when_payment_method_unchanged(): void {
    test_reset_http_stubs();

    $GLOBALS['test_options']['oen_merchant_id'] = 'merchant-123';
    $GLOBALS['test_options']['oen_api_token']   = 'sk_test_secret';

    $order = new WC_Order( 1013, 1234 );
    $order->update_meta_data( '_oen_session_id', 'sess_card' );
    $order->update_meta_data( '_oen_checkout_url', 'https://oen.tw/checkout/sess_card' );
    $order->update_meta_data( '_oen_payment_method', 'card' );
    $GLOBALS['test_wc_orders'][1013] = $order;

    $GLOBALS['test_http_get_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
                'id'          => 'sess_card',
                'status'      => 'created',
                'orderId'     => 'wc-order-1013',
                'amount'      => 1234,
                'checkoutUrl' => 'https://oen.tw/checkout/sess_card',
        ] ),
    ];

    $gateway = new Test_OEN_Gateway();
    $result  = $gateway->process_payment( 1013 );

    test_assert(
        'https://oen.tw/checkout/sess_card' === ( $result['redirect'] ?? null ),
        'An unchanged payment method must still reuse the existing session.'
    );
    test_assert(
        0 === count( $GLOBALS['test_http_post_calls'] ),
        'An unchanged payment method must neither create a new session nor cancel the current one.'
    );
}

/*
 * Orders created before the plugin recorded the payment method report an empty one.
 * Treating that as a mismatch would abandon and recreate the session of every order
 * that is mid-checkout during an upgrade.
 */
function test_process_payment_reuses_session_when_stored_payment_method_is_unknown(): void {
    test_reset_http_stubs();

    $GLOBALS['test_options']['oen_merchant_id'] = 'merchant-123';
    $GLOBALS['test_options']['oen_api_token']   = 'sk_test_secret';

    $order = new WC_Order( 1014, 1234 );
    $order->update_meta_data( '_oen_session_id', 'sess_legacy' );
    $order->update_meta_data( '_oen_checkout_url', 'https://oen.tw/checkout/sess_legacy' );
    $GLOBALS['test_wc_orders'][1014] = $order;

    $GLOBALS['test_http_get_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
                'id'          => 'sess_legacy',
                'status'      => 'created',
                'orderId'     => 'wc-order-1014',
                'amount'      => 1234,
                'checkoutUrl' => 'https://oen.tw/checkout/sess_legacy',
        ] ),
    ];

    $gateway = new Test_OEN_Gateway();
    $result  = $gateway->process_payment( 1014 );

    test_assert(
        'https://oen.tw/checkout/sess_legacy' === ( $result['redirect'] ?? null ),
        'An order with no recorded payment method must keep reusing its session.'
    );
    test_assert(
        0 === count( $GLOBALS['test_http_post_calls'] ),
        'An unknown stored payment method must not be treated as a change.'
    );
}

function test_cancel_session_uses_hosted_checkout_contract(): void {
    test_reset_http_stubs();

    $client = new OEN_API_Client( 'merchant-123', 'sk_test_secret' );

    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [ 'id' => 'sess_123', 'status' => 'cancelled' ] ),
    ];

    test_assert(
        method_exists( $client, 'cancel_session' ),
        'OEN_API_Client::cancel_session() should exist so an abandoned session can be closed '
            . 'instead of staying payable from a stale browser tab.'
    );

    $result = $client->cancel_session( 'sess_123' );

    test_assert(
        ( $GLOBALS['test_http_post_calls'][0]['url'] ?? null )
            === 'https://api.oen.tw/api/hosted-checkout/v1/sessions/sess_123/cancel',
        'cancel_session should POST to /hosted-checkout/v1/sessions/{id}/cancel.'
    );
    test_assert(
        ( $GLOBALS['test_http_post_calls'][0]['args']['headers']['Authorization'] ?? null ) === 'Bearer sk_test_secret',
        'cancel_session should authorize with the secret key.'
    );
    test_assert(
        ( $result['status'] ?? null ) === 'cancelled',
        'cancel_session should return the cancelled session resource.'
    );
}

function test_cancel_session_surfaces_api_rejection(): void {
    test_reset_http_stubs();

    $client = new OEN_API_Client( 'merchant-123', 'sk_test_secret' );

    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 409 ],
        'body'     => wp_json_encode( [
            'error' => [
                'code'    => 'SESSION_INVALID_STATE',
                'message' => 'session can no longer be cancelled',
            ],
        ] ),
    ];

    try {
        $client->cancel_session( 'sess_123' );
        throw new RuntimeException( 'cancel_session() should throw when the API refuses to cancel.' );
    } catch ( RuntimeException $exception ) {
        test_assert(
            str_contains( $exception->getMessage(), 'SESSION_INVALID_STATE' ),
            'A refused cancellation should surface the API error so callers can decide what to do.'
        );
    }
}



/*
 * AC1 exactly as reported: a credit card session exists, the buyer goes back, picks
 * convenience store and submits again. The CVS gateway must get a CVS session — not the
 * card page — and the order must go on hold for the off-site payment as usual.
 */
function test_process_payment_card_to_cvs_switch_creates_a_cvs_session(): void {
    test_reset_http_stubs();

    $GLOBALS['test_options']['oen_merchant_id'] = 'merchant-123';
    $GLOBALS['test_options']['oen_api_token']   = 'sk_test_secret';

    $order = new WC_Order( 1020, 1234 );
    $order->update_meta_data( '_oen_session_id', 'sess_card' );
    $order->update_meta_data( '_oen_checkout_url', 'https://oen.tw/checkout/sess_card' );
    $order->update_meta_data( '_oen_payment_method', 'card' );
    $GLOBALS['test_wc_orders'][1020] = $order;

    $GLOBALS['test_http_get_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
            'id'          => 'sess_card',
            'status'      => 'created',
            'orderId'     => 'wc-order-1020',
            'amount'      => 1234,
            'checkoutUrl' => 'https://oen.tw/checkout/sess_card',
        ] ),
    ];
    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
            'id'          => 'sess_cvs',
            'checkoutUrl' => 'https://oen.tw/checkout/sess_cvs',
        ] ),
    ];
    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [ 'id' => 'sess_card', 'status' => 'cancelled' ] ),
    ];

    $gateway = new Test_OEN_CVS_Gateway();
    $result  = $gateway->process_payment( 1020 );

    test_assert(
        'https://oen.tw/checkout/sess_cvs' === ( $result['redirect'] ?? null ),
        'Switching from card to convenience store must redirect to the new CVS checkout page, '
            . 'not back to the card page. Got: ' . var_export( $result, true )
    );
    test_assert(
        'sess_cvs' === $order->get_meta( '_oen_session_id' ) && 'cvs' === $order->get_meta( '_oen_payment_method' ),
        'The order must now point at the CVS session and record cvs as its method.'
    );
    test_assert(
        ( $GLOBALS['test_http_post_calls'][1]['url'] ?? null )
            === 'https://api.oen.tw/api/hosted-checkout/v1/sessions/sess_card/cancel',
        'The abandoned card session must be cancelled after the CVS session is created.'
    );
    test_assert(
        'on-hold' === $order->status,
        'A CVS checkout must still put the order on hold while the buyer pays off-site.'
    );
}

/*
 * A store can charge a fee for one method and not another, so switching method can
 * change the order total. The abandoned session was created for the old total; demanding
 * that it still match would refuse the switch and leave the buyer stuck on both methods.
 */
function test_process_payment_method_switch_tolerates_a_changed_total(): void {
    test_reset_http_stubs();

    $GLOBALS['test_options']['oen_merchant_id'] = 'merchant-123';
    $GLOBALS['test_options']['oen_api_token']   = 'sk_test_secret';

    // The order total now includes a CVS-only fee the card session never had.
    $order = new WC_Order( 1021, 1264 );
    $order->update_meta_data( '_oen_session_id', 'sess_card' );
    $order->update_meta_data( '_oen_payment_method', 'card' );
    $GLOBALS['test_wc_orders'][1021] = $order;

    $GLOBALS['test_http_get_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
            'id'      => 'sess_card',
            'status'  => 'created',
            'orderId' => 'wc-order-1021',
            'amount'  => 1234,
        ] ),
    ];
    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
            'id'          => 'sess_cvs',
            'checkoutUrl' => 'https://oen.tw/checkout/sess_cvs',
        ] ),
    ];
    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [ 'id' => 'sess_card', 'status' => 'cancelled' ] ),
    ];

    $gateway = new Test_OEN_CVS_Gateway();
    $result  = $gateway->process_payment( 1021 );

    test_assert(
        'https://oen.tw/checkout/sess_cvs' === ( $result['redirect'] ?? null ),
        'A method switch that changes the total must still start a session for the new '
            . 'method. Got: ' . var_export( $result, true )
    );
    $create_body = json_decode( (string) ( $GLOBALS['test_http_post_calls'][0]['args']['body'] ?? '' ), true );
    test_assert(
        1264 === ( $create_body['amount'] ?? null ),
        'The replacement session must be created for the current order total.'
    );
}

/*
 * The relaxed check is only for a session being abandoned. Reusing a session whose
 * amount no longer matches the order must still fail closed.
 */
function test_process_payment_same_method_with_changed_total_still_fails_closed(): void {
    test_reset_http_stubs();

    $GLOBALS['test_options']['oen_merchant_id'] = 'merchant-123';
    $GLOBALS['test_options']['oen_api_token']   = 'sk_test_secret';

    $order = new WC_Order( 1022, 1264 );
    $order->update_meta_data( '_oen_session_id', 'sess_card' );
    $order->update_meta_data( '_oen_checkout_url', 'https://oen.tw/checkout/sess_card' );
    $order->update_meta_data( '_oen_payment_method', 'card' );
    $GLOBALS['test_wc_orders'][1022] = $order;

    $GLOBALS['test_http_get_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
            'id'          => 'sess_card',
            'status'      => 'created',
            'orderId'     => 'wc-order-1022',
            'amount'      => 1234,
            'checkoutUrl' => 'https://oen.tw/checkout/sess_card',
        ] ),
    ];

    $gateway = new Test_OEN_Gateway();
    $result  = $gateway->process_payment( 1022 );

    test_assert(
        'failure' === ( $result['result'] ?? null ) && 0 === count( $GLOBALS['test_http_post_calls'] ),
        'Reusing a session whose amount no longer matches the order must still fail closed.'
    );
}

/*
 * A session that has already been paid must fail closed even when the method changed:
 * starting a second attempt would let the buyer pay the same order twice.
 */
function test_process_payment_method_switch_still_fails_closed_for_a_paid_session(): void {
    test_reset_http_stubs();

    $GLOBALS['test_options']['oen_merchant_id'] = 'merchant-123';
    $GLOBALS['test_options']['oen_api_token']   = 'sk_test_secret';

    $order = new WC_Order( 1023, 1264 );
    $order->update_meta_data( '_oen_session_id', 'sess_card' );
    $order->update_meta_data( '_oen_payment_method', 'card' );
    $GLOBALS['test_wc_orders'][1023] = $order;

    $GLOBALS['test_http_get_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
            'id'      => 'sess_card',
            'status'  => 'completed',
            'orderId' => 'wc-order-1023',
            'amount'  => 1234,
        ] ),
    ];

    $gateway = new Test_OEN_CVS_Gateway();
    $result  = $gateway->process_payment( 1023 );

    test_assert(
        'failure' === ( $result['result'] ?? null ),
        'A paid session must fail closed even after a method switch.'
    );
    test_assert(
        0 === count( $GLOBALS['test_http_post_calls'] ),
        'A paid session must be neither replaced nor cancelled.'
    );
}

/*
 * Abandoning a session cancels it, so the session must first be proven to belong to this
 * order. A session bound to a different order id must fail closed, never be cancelled.
 */
function test_process_payment_method_switch_never_cancels_a_foreign_session(): void {
    test_reset_http_stubs();

    $GLOBALS['test_options']['oen_merchant_id'] = 'merchant-123';
    $GLOBALS['test_options']['oen_api_token']   = 'sk_test_secret';

    $order = new WC_Order( 1024, 1234 );
    $order->update_meta_data( '_oen_session_id', 'sess_other' );
    $order->update_meta_data( '_oen_payment_method', 'card' );
    $GLOBALS['test_wc_orders'][1024] = $order;

    $GLOBALS['test_http_get_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
            'id'      => 'sess_other',
            'status'  => 'created',
            'orderId' => 'wc-order-9999',
            'amount'  => 1234,
        ] ),
    ];

    $gateway = new Test_OEN_CVS_Gateway();
    $result  = $gateway->process_payment( 1024 );

    test_assert(
        'failure' === ( $result['result'] ?? null ) && 0 === count( $GLOBALS['test_http_post_calls'] ),
        'A session bound to another order must fail closed and must not be cancelled.'
    );
}

test_create_session_uses_hosted_checkout_contract();
test_create_webhook_uses_hosted_checkout_contract();
test_create_session_rejects_missing_session_id();
test_create_session_uses_unique_idempotency_key_per_attempt();
test_get_session_uses_hosted_checkout_contract();
test_get_session_respects_runtime_api_base_url_override();
test_process_payment_reuses_existing_reusable_session();
test_process_payment_fails_closed_for_mismatched_reusable_session();
test_process_payment_fails_closed_for_completed_terminal_reusable_session();
test_process_payment_reuses_pending_session_with_top_level_status();
test_process_payment_fails_closed_for_authoritative_charged_session();
test_process_payment_fails_closed_for_unverified_failure_terminal_session();
test_process_payment_refreshes_terminal_session_and_clears_stale_transaction_hid();
test_process_payment_creates_new_session_when_payment_method_changed();
test_process_payment_cancels_the_superseded_session_after_the_replacement_exists();
test_process_payment_persists_the_replacement_session_before_cancelling_the_old_one();
test_process_payment_survives_a_failed_supersede_cancellation();
test_process_payment_reuses_session_when_payment_method_unchanged();
test_process_payment_reuses_session_when_stored_payment_method_is_unknown();
test_process_payment_card_to_cvs_switch_creates_a_cvs_session();
test_process_payment_method_switch_tolerates_a_changed_total();
test_process_payment_same_method_with_changed_total_still_fails_closed();
test_process_payment_method_switch_still_fails_closed_for_a_paid_session();
test_process_payment_method_switch_never_cancels_a_foreign_session();
test_cancel_session_uses_hosted_checkout_contract();
test_cancel_session_surfaces_api_rejection();

echo "API client smoke harness passed.\n";
