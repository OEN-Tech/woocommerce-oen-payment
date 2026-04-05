<?php

$case = $args[0] ?? '';

$fixtures = [
    'signed-ambiguous' => [
        'oen_order_id' => 'wc-runtime-signed-ambiguous',
        'session_id'   => 'sess_runtime_signed_ambiguous',
        'total'        => 1234,
    ],
    'signed-missing-amount' => [
        'oen_order_id' => 'wc-runtime-signed-missing-amount',
        'session_id'   => 'sess_runtime_signed_missing_amount',
        'total'        => 1234,
    ],
    'invalid-signature' => [
        'oen_order_id' => 'wc-runtime-signed-ambiguous',
        'session_id'   => 'sess_runtime_signed_ambiguous',
        'total'        => 1234,
    ],
];

if ( ! isset( $fixtures[ $case ] ) ) {
    fwrite( STDERR, 'Unknown runtime case: ' . $case . PHP_EOL );
    exit( 1 );
}

$fixture = $fixtures[ $case ];

$existing_orders = wc_get_orders( [
    'meta_key'   => '_oen_order_id',
    'meta_value' => $fixture['oen_order_id'],
    'limit'      => -1,
    'return'     => 'objects',
] );

foreach ( $existing_orders as $existing_order ) {
    $existing_order->delete( true );
}

$order = wc_create_order();
$order->set_status( 'pending' );
$order->set_currency( 'TWD' );
$order->set_total( (float) $fixture['total'] );
$order->set_payment_method( 'oen_credit' );
$order->set_payment_method_title( 'OEN Credit' );
$order->update_meta_data( '_oen_order_id', $fixture['oen_order_id'] );
$order->update_meta_data( '_oen_session_id', $fixture['session_id'] );
$order->save();

echo wp_json_encode( [
    'order_id'     => $order->get_id(),
    'oen_order_id' => $fixture['oen_order_id'],
    'session_id'   => $fixture['session_id'],
] );
