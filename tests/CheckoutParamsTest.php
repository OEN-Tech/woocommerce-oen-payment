<?php

declare( strict_types=1 );

/**
 * Locks the create-session request contract that the Hosted Checkout backend
 * enforces but the existing harnesses do not cover:
 *
 *  - productDetails must reconcile to `amount` (Σ unitPrice×quantity === total),
 *    otherwise the backend rejects the session with PRODUCT_AMOUNT_NOT_MATCH
 *    (legacy code V0001). build_product_details() guarantees this with an ADJ line.
 *  - allowedPaymentMethods must use the backend's exact enum strings
 *    (`cvs`; card is the backend default and must be omitted, not sent as `card`).
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'OEN_PAYMENT_PLUGIN_URL' ) ) {
    define( 'OEN_PAYMENT_PLUGIN_URL', 'https://store.example/wp-content/plugins/woocommerce-oen-payment/' );
}
if ( ! defined( 'OEN_PAYMENT_VERSION' ) ) {
    define( 'OEN_PAYMENT_VERSION', 'test' );
}

if ( ! function_exists( 'add_action' ) ) {
    function add_action( string $hook, array $callback ): void {}
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
        protected array $settings = [];

        public function init_settings(): void {}

        public function get_option( string $key, mixed $default = '' ): mixed {
            return $this->settings[ $key ] ?? $default;
        }

        public function get_return_url( mixed $order = null ): string {
            return 'https://store.example/thank-you';
        }

        public function is_available(): bool {
            return true;
        }

        public function process_admin_options(): void {}
    }
}

class Test_WC_Product {
    public function __construct( private string $sku, private int $id ) {}

    public function get_sku(): string {
        return $this->sku;
    }

    public function get_id(): int {
        return $this->id;
    }
}

class Test_WC_Item {
    public function __construct(
        private string $name,
        private int $qty,
        private float $unit_total,
        private ?Test_WC_Product $product
    ) {}

    public function get_product(): ?Test_WC_Product {
        return $this->product;
    }

    public function get_name(): string {
        return $this->name;
    }

    public function get_quantity(): int {
        return $this->qty;
    }

    public function get_product_id(): int {
        return $this->product?->get_id() ?? 0;
    }

    public function unit_total(): float {
        return $this->unit_total;
    }
}

class Test_WC_Fee {
    public function __construct( private string $name, private float $total, private float $tax ) {}

    public function get_name(): string {
        return $this->name;
    }

    public function get_total(): float {
        return $this->total;
    }

    public function get_total_tax(): float {
        return $this->tax;
    }
}

if ( ! class_exists( 'WC_Order', false ) ) {
    class WC_Order {
        private array $meta = [];

        public function __construct(
            private int $id,
            private int $total,
            private array $items = [],
            private array $fees = [],
            private int $shipping = 0,
            private int $shipping_tax = 0
        ) {}

        public function get_id(): int {
            return $this->id;
        }

        public function get_total(): int {
            return $this->total;
        }

        public function get_items(): array {
            return $this->items;
        }

        public function get_fees(): array {
            return $this->fees;
        }

        public function get_shipping_total(): int {
            return $this->shipping;
        }

        public function get_shipping_tax(): int {
            return $this->shipping_tax;
        }

        public function get_item_total( mixed $item, bool $inc_tax = false ): float {
            return $item->unit_total();
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

        public function get_meta( string $key ): mixed {
            return $this->meta[ $key ] ?? '';
        }

        public function update_meta_data( string $key, mixed $value ): void {
            $this->meta[ $key ] = $value;
        }

        public function save(): void {}

        public function is_paid(): bool {
            return false;
        }
    }
}

require_once __DIR__ . '/../includes/class-wc-gateway-oen.php';
require_once __DIR__ . '/../includes/class-wc-gateway-oen-credit.php';
require_once __DIR__ . '/../includes/class-wc-gateway-oen-cvs.php';

class Exposed_OEN_CVS extends WC_Gateway_OEN_CVS {
    public function product_details( WC_Order $order ): array {
        return $this->build_product_details( $order );
    }

    public function checkout_params( WC_Order $order ): array {
        return $this->build_checkout_params( $order );
    }
}

class Exposed_OEN_Credit extends WC_Gateway_OEN_Credit {
    public function checkout_params( WC_Order $order ): array {
        return $this->build_checkout_params( $order );
    }
}

function cp_sum( array $details ): int {
    $sum = 0;
    foreach ( $details as $line ) {
        $sum += (int) $line['unitPrice'] * (int) $line['quantity'];
    }
    return $sum;
}

function test_product_details_single_line_when_itemization_off(): void {
    $GLOBALS['test_options'] = [ 'oen_display_item_name' => 'no' ];

    $details = ( new Exposed_OEN_CVS() )->product_details( new WC_Order( 1, 1000 ) );

    test_assert( 1 === count( $details ), 'Itemization off should produce a single ORDER line.' );
    test_assert( 'ORDER' === $details[0]['productionCode'], 'The single line should use the ORDER production code.' );
    test_assert( 1000 === cp_sum( $details ), 'The single ORDER line unitPrice must equal the order total.' );
}

function test_product_details_adds_adjustment_line_to_reconcile_amount(): void {
    // total 1000; item qty 3 @ rounded unit 333 = 999; an ADJ +1 line must reconcile to 1000.
    $GLOBALS['test_options'] = [ 'oen_display_item_name' => 'yes' ];

    $item    = new Test_WC_Item( 'Widget', 3, 333.0, new Test_WC_Product( 'SKU-1', 42 ) );
    $details = ( new Exposed_OEN_CVS() )->product_details( new WC_Order( 2, 1000, [ $item ] ) );
    $codes   = array_column( $details, 'productionCode' );

    test_assert( 1000 === cp_sum( $details ), 'sum(unitPrice*quantity) must equal the order total (ADJ reconciliation prevents V0001).' );
    test_assert( in_array( 'ADJ', $codes, true ), 'An ADJ line should be added when the itemized sum != order total.' );
    test_assert( in_array( 'SKU-1', $codes, true ), 'An item production code should use the product SKU when present.' );
}

function test_product_details_includes_fees_and_shipping_and_reconciles(): void {
    // item 2x200=400, fee 50+5tax=55, shipping 100 -> 555 total, diff 0 (no ADJ needed).
    $GLOBALS['test_options'] = [ 'oen_display_item_name' => 'yes' ];

    $item    = new Test_WC_Item( 'Thing', 2, 200.0, new Test_WC_Product( '', 7 ) );
    $fee     = new Test_WC_Fee( 'Surcharge', 50.0, 5.0 );
    $details = ( new Exposed_OEN_CVS() )->product_details( new WC_Order( 3, 555, [ $item ], [ $fee ], 100, 0 ) );
    $codes   = array_column( $details, 'productionCode' );

    test_assert( in_array( 'FEE', $codes, true ), 'Fees should appear as a FEE line.' );
    test_assert( in_array( 'SHIPPING', $codes, true ), 'Shipping should appear as a SHIPPING line.' );
    test_assert( in_array( '7', $codes, true ), 'An empty SKU should fall back to the product id.' );
    test_assert( 555 === cp_sum( $details ), 'Itemized sum with fees + shipping must reconcile to the order total.' );
}

function test_cvs_gateway_restricts_allowed_payment_methods_to_cvs(): void {
    $GLOBALS['test_options'] = [ 'oen_display_item_name' => 'no' ];

    $params = ( new Exposed_OEN_CVS() )->checkout_params( new WC_Order( 4, 1000 ) );

    test_assert(
        [ 'cvs' ] === ( $params['allowedPaymentMethods'] ?? null ),
        'The CVS gateway must send allowedPaymentMethods=[cvs] (exact backend enum string).'
    );
}

function test_credit_gateway_omits_allowed_payment_methods(): void {
    $GLOBALS['test_options'] = [ 'oen_display_item_name' => 'no' ];

    $params = ( new Exposed_OEN_Credit() )->checkout_params( new WC_Order( 5, 1000 ) );

    test_assert(
        ! array_key_exists( 'allowedPaymentMethods', $params ),
        'The Credit gateway should omit allowedPaymentMethods (card is the backend default; sending "card" is redundant).'
    );
}

test_product_details_single_line_when_itemization_off();
test_product_details_adds_adjustment_line_to_reconcile_amount();
test_product_details_includes_fees_and_shipping_and_reconciles();
test_cvs_gateway_restricts_allowed_payment_methods_to_cvs();
test_credit_gateway_omits_allowed_payment_methods();

echo "Checkout params harness passed.\n";
