<?php

$case = $args[0] ?? '';

$fixtures = [
    'signed-ambiguous' => 'wc-runtime-signed-ambiguous',
    'signed-success' => 'wc-runtime-signed-success',
    'signed-stale' => 'wc-runtime-signed-stale',
    'signed-missing-amount' => 'wc-runtime-signed-missing-amount',
    'invalid-signature' => 'wc-runtime-signed-ambiguous',
    'cvs-pending' => 'wc-runtime-cvs-pending',
];

if ( ! isset( $fixtures[ $case ] ) ) {
    fwrite( STDERR, 'Unknown runtime case: ' . $case . PHP_EOL );
    exit( 1 );
}

$orders = wc_get_orders( [
    'meta_key'   => '_oen_order_id',
    'meta_value' => $fixtures[ $case ],
    'limit'      => 1,
    'return'     => 'objects',
] );

$order = $orders[0] ?? null;

if ( ! $order instanceof WC_Order ) {
    fwrite( STDERR, 'Runtime order not found for case: ' . $case . PHP_EOL );
    exit( 1 );
}

echo wp_json_encode( [
    'order_id'             => $order->get_id(),
    'status'               => $order->get_status(),
    'is_paid'              => $order->is_paid(),
    'oen_paid_at'          => (string) $order->get_meta( '_oen_paid_at' ),
    'oen_transaction_hid'  => (string) $order->get_meta( '_oen_transaction_hid' ),
    'oen_transaction_id'   => (string) $order->get_meta( '_oen_transaction_id' ),
    'oen_cvs_code'         => (string) $order->get_meta( '_oen_cvs_code' ),
] );
