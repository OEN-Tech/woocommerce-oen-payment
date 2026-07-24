<?php

/**
 * Dump the OEN-relevant state of a WooCommerce order for L3 evidence capture.
 *
 *   wp eval-file wp-content/plugins/woocommerce-oen-payment/tests/e2e/inspect-order-by-id.php <order_id>
 */

$order_id = (int) ( $args[0] ?? 0 );
$order    = $order_id > 0 ? wc_get_order( $order_id ) : null;

if ( ! $order instanceof WC_Order ) {
    fwrite( STDERR, "order not found: {$order_id}\n" );
    exit( 1 );
}

echo wp_json_encode( [
    'order_id'             => $order->get_id(),
    'status'               => $order->get_status(),
    'is_paid'              => $order->is_paid(),
    'total'                => $order->get_total(),
    'oen_order_id'         => (string) $order->get_meta( '_oen_order_id' ),
    'oen_session_id'       => (string) $order->get_meta( '_oen_session_id' ),
    'oen_checkout_url'     => (string) $order->get_meta( '_oen_checkout_url' ),
    'oen_transaction_hid'  => (string) $order->get_meta( '_oen_transaction_hid' ),
    'oen_transaction_id'   => (string) $order->get_meta( '_oen_transaction_id' ),
    'oen_paid_at'          => (string) $order->get_meta( '_oen_paid_at' ),
    'oen_cvs_name'         => (string) $order->get_meta( '_oen_cvs_name' ),
    'oen_cvs_code'         => (string) $order->get_meta( '_oen_cvs_code' ),
    'oen_cvs_expired_at'   => (string) $order->get_meta( '_oen_cvs_expired_at' ),
], JSON_PRETTY_PRINT ) . "\n";
