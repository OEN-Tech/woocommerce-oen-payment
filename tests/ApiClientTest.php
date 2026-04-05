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

        public function save(): void {}

        public function is_paid(): bool {
            return $this->paid;
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

function test_reset_http_stubs(): void {
    $GLOBALS['test_http_post_calls'] = [];
    $GLOBALS['test_http_get_calls']  = [];
    $GLOBALS['test_http_post_queue'] = [];
    $GLOBALS['test_http_get_queue']   = [];
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
            'code' => 'S0000',
            'data' => [
                'id'          => 'sess_123',
                'checkoutUrl' => 'https://oen.tw/checkout/sess_123',
            ],
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
        ( $GLOBALS['test_http_post_calls'][0]['url'] ?? null ) === 'https://api.oen.tw/hosted-checkout/v1/sessions',
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
            'code' => 'S0000',
            'data' => [
                'checkoutUrl' => 'https://oen.tw/checkout/sess_missing',
            ],
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
            'code' => 'S0000',
            'data' => [
                'id'          => 'sess_123',
                'checkoutUrl' => 'https://oen.tw/checkout/sess_123',
            ],
        ] ),
    ];
    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
            'code' => 'S0000',
            'data' => [
                'id'          => 'sess_456',
                'checkoutUrl' => 'https://oen.tw/checkout/sess_456',
            ],
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
            'code' => 'S0000',
            'data' => [
                'id'     => 'sess_123',
                'status' => 'pending',
            ],
        ] ),
    ];

    test_assert(
        method_exists( $client, 'get_session' ),
        'OEN_API_Client::get_session() should exist for the Hosted Checkout contract.'
    );

    $result = $client->get_session( 'sess_123' );

    test_assert(
        ( $GLOBALS['test_http_get_calls'][0]['url'] ?? null ) === 'https://api.oen.tw/hosted-checkout/v1/sessions/sess_123',
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
            'code' => 'S0000',
            'data' => [
                'id'          => 'sess_existing',
                'status'      => 'pending',
                'orderId'     => 'wc-order-1001',
                'amount'      => 1234,
                'checkoutUrl' => 'https://oen.tw/checkout/sess_existing',
                'transaction' => [
                    'status' => 'pending',
                ],
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
            'code' => 'S0000',
            'data' => [
                'id'          => 'sess_existing',
                'status'      => 'pending',
                'orderId'     => 'wc-order-9999',
                'amount'      => 4321,
                'checkoutUrl' => 'https://oen.tw/checkout/sess_existing',
                'transaction' => [
                    'status' => 'pending',
                ],
            ],
        ] ),
    ];
    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
            'code' => 'S0000',
            'data' => [
                'id'          => 'sess_fresh',
                'checkoutUrl' => 'https://oen.tw/checkout/sess_fresh',
            ],
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

function test_process_payment_fails_closed_for_ambiguous_completed_reusable_session(): void {
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
            'code' => 'S0000',
            'data' => [
                'id'          => 'sess_completed',
                'status'      => 'completed',
                'orderId'     => 'wc-order-1004',
                'amount'      => 2468,
                'checkoutUrl' => 'https://oen.tw/checkout/sess_completed',
            ],
        ] ),
    ];

    $gateway = new Test_OEN_Gateway();
    $result  = $gateway->process_payment( 1004 );

    test_assert(
        'failure' === ( $result['result'] ?? null ),
        'process_payment() should fail closed when a reusable session reports only top-level completed without transaction.status.'
    );
    test_assert(
        0 === count( $GLOBALS['test_http_post_calls'] ),
        'process_payment() should not create a fresh session when the existing session completion state is ambiguous.'
    );
}

function test_process_payment_fails_closed_for_pending_session_without_authoritative_status(): void {
    test_reset_http_stubs();

    $GLOBALS['test_options']['oen_merchant_id'] = 'merchant-123';
    $GLOBALS['test_options']['oen_api_token']   = 'sk_test_secret';

    $order = new WC_Order( 1006, 8642 );
    $order->update_meta_data( '_oen_session_id', 'sess_pending' );
    $order->update_meta_data( '_oen_checkout_url', 'https://oen.tw/checkout/sess_pending' );
    $GLOBALS['test_wc_orders'][1006] = $order;

    $GLOBALS['test_http_get_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
            'code' => 'S0000',
            'data' => [
                'id'          => 'sess_pending',
                'status'      => 'pending',
                'orderId'     => 'wc-order-1006',
                'amount'      => 8642,
                'checkoutUrl' => 'https://oen.tw/checkout/sess_pending',
            ],
        ] ),
    ];
    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
            'code' => 'S0000',
            'data' => [
                'id'          => 'sess_should_not_exist',
                'checkoutUrl' => 'https://oen.tw/checkout/sess_should_not_exist',
            ],
        ] ),
    ];

    $gateway = new Test_OEN_Gateway();
    $result  = $gateway->process_payment( 1006 );

    test_assert(
        'failure' === ( $result['result'] ?? null ),
        'process_payment() should fail closed when transaction.status is missing, even if top-level session status is pending.'
    );
    test_assert(
        0 === count( $GLOBALS['test_http_post_calls'] ),
        'process_payment() should not create a new session when authoritative transaction.status is missing.'
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

    $GLOBALS['test_http_get_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
            'code' => 'S0000',
            'data' => [
                'id'          => 'sess_charged',
                'status'      => 'pending',
                'orderId'     => 'wc-order-1005',
                'amount'      => 1357,
                'checkoutUrl' => 'https://oen.tw/checkout/sess_charged',
                'transaction' => [
                    'status' => 'charged',
                ],
            ],
        ] ),
    ];
    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
            'code' => 'S0000',
            'data' => [
                'id'          => 'sess_should_not_exist',
                'checkoutUrl' => 'https://oen.tw/checkout/sess_should_not_exist',
            ],
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
            'code' => 'S0000',
            'data' => [
                'id'          => 'sess_failed',
                'status'      => 'failed',
                'orderId'     => 'wc-order-9999',
                'amount'      => 9753,
                'checkoutUrl' => 'https://oen.tw/checkout/sess_failed',
                'transaction' => [
                    'status' => 'failed',
                ],
            ],
        ] ),
    ];
    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
            'code' => 'S0000',
            'data' => [
                'id'          => 'sess_should_not_exist',
                'checkoutUrl' => 'https://oen.tw/checkout/sess_should_not_exist',
            ],
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
            'code' => 'S0000',
            'data' => [
                'id'      => 'sess_old',
                'status'  => 'expired',
                'orderId' => 'wc-order-1002',
                'amount'  => 5678,
                'transaction' => [
                    'status' => 'expired',
                ],
            ],
        ] ),
    ];
    $GLOBALS['test_http_post_queue'][] = [
        'response' => [ 'code' => 200 ],
        'body'     => wp_json_encode( [
            'code' => 'S0000',
            'data' => [
                'id'          => 'sess_new',
                'checkoutUrl' => 'https://oen.tw/checkout/sess_new',
            ],
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

test_create_session_uses_hosted_checkout_contract();
test_create_session_rejects_missing_session_id();
test_create_session_uses_unique_idempotency_key_per_attempt();
test_get_session_uses_hosted_checkout_contract();
test_process_payment_reuses_existing_reusable_session();
test_process_payment_fails_closed_for_mismatched_reusable_session();
test_process_payment_fails_closed_for_ambiguous_completed_reusable_session();
test_process_payment_fails_closed_for_pending_session_without_authoritative_status();
test_process_payment_fails_closed_for_authoritative_charged_session();
test_process_payment_fails_closed_for_unverified_failure_terminal_session();
test_process_payment_refreshes_terminal_session_and_clears_stale_transaction_hid();

echo "API client smoke harness passed.\n";
