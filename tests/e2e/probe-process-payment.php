<?php

/**
 * Create a real WooCommerce order and run the OEN gateway's process_payment()
 * against the configured (real dev) Hosted Checkout backend — proving the
 * plugin's create side end-to-end through actual plugin code (build_checkout_params
 * from a real order → create_session → meta storage → redirect result).
 *
 *   wp eval-file .../tests/e2e/probe-process-payment.php <product_id>
 */

$product_id = (int) ( $args[0] ?? 0 );

$order = wc_create_order();
$order->set_status( 'pending' );
$order->set_currency( 'TWD' );
if ( $product_id > 0 ) {
    $product = wc_get_product( $product_id );
    if ( $product ) {
        $order->add_product( $product, 1 );
    }
}
$order->set_billing_first_name( 'Test' );
$order->set_billing_last_name( 'Buyer' );
$order->set_billing_email( 'buyer@example.com' );
$order->calculate_totals();
$order->save();

try {
    $gateway = new WC_Gateway_OEN_Credit();
    $result  = $gateway->process_payment( $order->get_id() );
    $order   = wc_get_order( $order->get_id() );

    echo wp_json_encode( [
        'order_id'         => $order->get_id(),
        'total'            => $order->get_total(),
        'result'           => $result['result'] ?? null,
        'redirect'         => $result['redirect'] ?? null,
        'oen_order_id'     => (string) $order->get_meta( '_oen_order_id' ),
        'oen_session_id'   => (string) $order->get_meta( '_oen_session_id' ),
        'oen_checkout_url' => (string) $order->get_meta( '_oen_checkout_url' ),
        'status'           => $order->get_status(),
    ], JSON_PRETTY_PRINT ) . "\n";
} catch ( \Throwable $e ) {
    echo wp_json_encode( [ 'error' => $e->getMessage() ] ) . "\n";
}
