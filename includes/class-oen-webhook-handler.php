<?php

defined( 'ABSPATH' ) || exit;

/**
 * Handles incoming OEN Payment webhook callbacks.
 *
 * Registered at /?wc-api=oen_payment. OEN sends POST with JSON payload
 * when a transaction status changes (e.g., ATM/CVS payment completed).
 * Also handles credit card webhook confirmations.
 */
class OEN_Webhook_Handler {

    public function __construct() {
        add_action( 'woocommerce_api_oen_payment', [ $this, 'handle' ] );
    }

    /**
     * Process the incoming webhook payload.
     */
    public function handle(): void {
        $raw_body = file_get_contents( 'php://input' );
        $payload  = json_decode( $raw_body, true );

        if ( ! is_array( $payload ) || empty( $payload['orderId'] ) ) {
            $this->log( 'Invalid webhook payload: missing orderId', $raw_body );
            wp_send_json( [ 'status' => 'error', 'message' => 'Invalid payload' ], 400 );
            return;
        }

        // Sanitize all external string fields to prevent HTML injection in
        // order notes, meta values, and log entries.
        $payload['orderId']        = sanitize_text_field( $payload['orderId'] );
        $payload['transactionHid'] = sanitize_text_field( $payload['transactionHid'] ?? '' );
        $payload['status']         = sanitize_text_field( $payload['status'] ?? '' );
        $payload['message']        = sanitize_text_field( $payload['message'] ?? '' );

        $order = $this->find_order_by_oen_order_id( $payload['orderId'] );

        if ( ! $order ) {
            $this->log( 'Order not found for OEN orderId: ' . $payload['orderId'] );
            wp_send_json( [ 'status' => 'error', 'message' => 'Order not found' ], 404 );
            return;
        }

        // Skip if the order is already completed or processing.
        if ( $order->is_paid() ) {
            $this->log( 'Order #' . $order->get_id() . ' already paid, skipping webhook.' );
            wp_send_json( [ 'status' => 'ok', 'message' => 'Already processed' ] );
            return;
        }

        // Defense-in-depth: verify webhook amount matches order total.
        $webhook_amount = $payload['amount'] ?? null;
        $order_total    = intval( $order->get_total() );
        if ( null !== $webhook_amount && intval( $webhook_amount ) !== $order_total ) {
            $this->log(
                sprintf(
                    'Amount mismatch for order #%d: webhook=%s, order=%d',
                    $order->get_id(),
                    $webhook_amount,
                    $order_total
                )
            );
            wp_send_json( [ 'status' => 'error', 'message' => 'Amount mismatch' ], 400 );
            return;
        }

        $success = $payload['success'] ?? false;
        $status  = $payload['status'] ?? '';

        if ( $success && 'charged' === $status ) {
            $this->handle_success( $order, $payload );
        } else {
            $this->handle_failure( $order, $payload );
        }

        wp_send_json( [ 'status' => 'ok' ] );
    }

    /**
     * Handle a successful payment webhook.
     *
     * @param \WC_Order $order   The WooCommerce order.
     * @param array     $payload The webhook payload.
     */
    private function handle_success( \WC_Order $order, array $payload ): void {
        $transaction_hid = $payload['transactionHid'] ?? '';

        // Store payment metadata.
        $order->update_meta_data( '_oen_paid_at', current_time( 'c' ) );

        // Store CVS-specific metadata if present.
        $payment_info = $payload['paymentInfo'] ?? [];
        if ( ! empty( $payment_info['cvsName'] ) ) {
            $order->update_meta_data( '_oen_cvs_name', sanitize_text_field( $payment_info['cvsName'] ) );
        }
        if ( ! empty( $payment_info['code'] ) ) {
            $order->update_meta_data( '_oen_cvs_code', sanitize_text_field( $payment_info['code'] ) );
        }
        if ( ! empty( $payment_info['expiredAt'] ) ) {
            $order->update_meta_data( '_oen_cvs_expired_at', sanitize_text_field( $payment_info['expiredAt'] ) );
        }

        $order->save();

        // Mark payment as complete — transitions order to "processing".
        $order->payment_complete( $transaction_hid );

        $order->add_order_note(
            sprintf(
                /* translators: %s: OEN transaction HID */
                __( 'OEN Payment completed. Transaction: %s', 'woocommerce-oen-payment' ),
                $transaction_hid
            )
        );

        $this->log( 'Payment completed for order #' . $order->get_id() . ' (txn: ' . $transaction_hid . ')' );
    }

    /**
     * Handle a failed payment webhook.
     *
     * @param \WC_Order $order   The WooCommerce order.
     * @param array     $payload The webhook payload.
     */
    private function handle_failure( \WC_Order $order, array $payload ): void {
        $message = $payload['message'] ?: __( 'Payment failed.', 'woocommerce-oen-payment' );

        $order->update_status(
            'failed',
            sprintf(
                /* translators: %s: failure reason */
                __( 'OEN Payment failed: %s', 'woocommerce-oen-payment' ),
                $message
            )
        );

        $this->log( 'Payment failed for order #' . $order->get_id() . ': ' . $message );
    }

    /**
     * Find a WC order by the OEN orderId stored in meta.
     *
     * @param string $oen_order_id The prefixed order ID sent to OEN.
     * @return \WC_Order|null
     */
    private function find_order_by_oen_order_id( string $oen_order_id ): ?\WC_Order {
        $orders = wc_get_orders( [
            'meta_key'   => '_oen_order_id',
            'meta_value' => $oen_order_id,
            'limit'      => 1,
        ] );

        return $orders[0] ?? null;
    }

    /**
     * Log a webhook event for debugging.
     *
     * @param string $message Log message.
     * @param string $context Additional context (raw body, etc.).
     */
    private function log( string $message, string $context = '' ): void {
        $logger = wc_get_logger();
        $logger->info( $message, [ 'source' => 'oen-payment-webhook' ] );
        if ( $context ) {
            $logger->debug( $context, [ 'source' => 'oen-payment-webhook' ] );
        }
    }
}
