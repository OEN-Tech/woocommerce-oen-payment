<?php

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-oen-webhook-parser.php';

/**
 * Handles incoming OEN Payment webhook callbacks.
 *
 * Registered at /?wc-api=oen_payment. OEN sends POST with a hosted checkout
 * event envelope whose business payload lives under the nested data field.
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

        try {
            $payload = $this->parse_webhook_payload( $raw_body );
        } catch ( \Throwable $exception ) {
            $status_code = $this->get_parser_status_code( $exception );
            $this->log( $exception->getMessage(), $raw_body );
            wp_send_json( [ 'status' => 'error', 'message' => $exception->getMessage() ], $status_code );
            return;
        }

        if ( ! is_array( $payload ) || empty( $payload['orderId'] ) ) {
            $this->log( 'Invalid webhook payload: missing orderId in event data', $raw_body );
            wp_send_json( [ 'status' => 'error', 'message' => 'Invalid payload' ], 400 );
            return;
        }

        // Sanitize external string fields to prevent HTML injection in order notes,
        // meta values, and log entries.
        $payload['sessionId']       = sanitize_text_field( $payload['sessionId'] ?? '' );
        $payload['orderId']         = sanitize_text_field( $payload['orderId'] );
        $payload['transactionHid']  = sanitize_text_field( $payload['transactionHid'] ?? '' );
        $payload['transactionId']   = sanitize_text_field( $payload['transactionId'] ?? '' );
        $payload['status']          = sanitize_text_field( $payload['status'] ?? '' );
        $payload['message']         = sanitize_text_field( $payload['message'] ?? '' );
        $payload['paymentMethod']   = sanitize_text_field( $payload['paymentMethod'] ?? '' );
        $payload['paymentProvider'] = sanitize_text_field( $payload['paymentProvider'] ?? '' );

        // Server-side verification requires transactionHid.
        $transaction_hid = $payload['transactionHid'];
        if ( empty( $transaction_hid ) ) {
            $this->log( 'Missing transactionHid in webhook payload', $raw_body );
            wp_send_json( [ 'status' => 'error', 'message' => 'Missing transaction ID' ], 400 );
            return;
        }

        $order = $this->find_order_by_oen_order_id( $payload['orderId'] );

        if ( ! $order ) {
            $this->log( 'Order not found for OEN orderId: ' . $payload['orderId'] );
            wp_send_json( [ 'status' => 'error', 'message' => 'Order not found' ], 404 );
            return;
        }

        // Acquire DB-level lock to prevent concurrent webhook processing for the same order.
        if ( ! $this->acquire_lock( $order->get_id() ) ) {
            $this->log( 'Order #' . $order->get_id() . ' is being processed by another request.' );
            wp_send_json( [ 'status' => 'error', 'message' => 'Processing in progress' ], 409 );
            return;
        }

        // Process under lock. Collect response before releasing — wp_send_json() calls exit,
        // so we must release the lock explicitly before sending the response.
        $response      = [ 'status' => 'ok' ];
        $response_code = 200;
        $order_id      = $order->get_id();

        try {
            // Re-read order after acquiring lock — the other request may have completed.
            $order = wc_get_order( $order_id );

            if ( ! $order ) {
                $this->log( 'Order #' . $order_id . ' not found after lock acquisition.' );
                $response      = [ 'status' => 'error', 'message' => 'Order not found' ];
                $response_code = 404;
            } elseif ( $order->is_paid() ) {
                $this->log( 'Order #' . $order_id . ' already paid, skipping webhook.' );
                $response = [ 'status' => 'ok', 'message' => 'Already processed' ];
            } else {
                // Step 2: Server-side verification — query OEN API for authoritative transaction state.
                $transaction = $this->verify_transaction( $transaction_hid, $order );
                if ( null === $transaction ) {
                    // verify_transaction already logged the error.
                    $response      = [ 'status' => 'error', 'message' => 'Verification failed' ];
                    $response_code = 502;
                } else {
                    $status = $transaction['status'] ?? '';

                    if ( 'charged' === $status ) {
                        $this->handle_success( $order, $transaction );
                    } else {
                        $this->handle_failure( $order, $transaction );
                    }
                }
            }
        } finally {
            $this->release_lock( $order_id );
        }

        wp_send_json( $response, $response_code );
    }

    /**
     * Parse the hosted checkout webhook envelope and return its nested data payload.
     *
     * @param string $raw_body Raw request body.
     * @return array<string, mixed>
     */
    private function parse_webhook_payload( string $raw_body ): array {
        $parser = new OEN_Webhook_Parser( get_option( 'oen_webhook_secret', '' ) );

        return $parser->parse( $raw_body, $this->get_signature_header() );
    }

    /**
     * Resolve the OenPay-Signature header from common PHP server variables.
     */
    private function get_signature_header(): string {
        if ( isset( $_SERVER['HTTP_OENPAY_SIGNATURE'] ) ) {
            return (string) $_SERVER['HTTP_OENPAY_SIGNATURE'];
        }

        if ( function_exists( 'getallheaders' ) ) {
            foreach ( getallheaders() as $name => $value ) {
                if ( 0 === strcasecmp( $name, 'OenPay-Signature' ) ) {
                    return is_string( $value ) ? $value : '';
                }
            }
        }

        return '';
    }

    /**
     * Map parser exceptions to HTTP status codes.
     */
    private function get_parser_status_code( \Throwable $exception ): int {
        $code = (int) $exception->getCode();

        if ( $code >= 400 && $code < 600 ) {
            return $code;
        }

        return 400;
    }

    /**
     * Verify transaction via OEN API server-side call.
     *
     * Never trust webhook payload for payment decisions — always confirm
     * transaction status, amount, and order binding with a direct API query.
     *
     * @param string    $transaction_hid The OEN transaction HID.
     * @param \WC_Order $order           The WooCommerce order.
     * @return array|null Verified transaction data, or null on failure.
     */
    private function verify_transaction( string $transaction_hid, \WC_Order $order ): ?array {
        try {
            $api         = OEN_API_Client::from_settings();
            $transaction = $api->get_transaction( $transaction_hid );
        } catch ( \Throwable $e ) {
            $this->log(
                sprintf( 'API verification failed for order #%d: %s', $order->get_id(), $e->getMessage() )
            );
            return null;
        }

        // Verify the transaction belongs to this order (prevent cross-order replay).
        // Compare against stored meta, not webhook payload (which is attacker-controlled).
        $api_order_id = $transaction['orderId'] ?? '';
        $expected_id  = $order->get_meta( '_oen_order_id' );

        if ( $api_order_id !== $expected_id ) {
            $this->log(
                sprintf(
                    'Order ID mismatch for order #%d: api=%s, expected=%s',
                    $order->get_id(),
                    sanitize_text_field( $api_order_id ),
                    $expected_id
                )
            );
            return null;
        }

        // Verify amount matches order total (OEN API returns integer TWD amount).
        $api_amount  = intval( $transaction['amount'] ?? 0 );
        $order_total = intval( $order->get_total() );

        if ( $api_amount !== $order_total ) {
            $this->log(
                sprintf(
                    'Amount mismatch for order #%d: api=%d, order=%d',
                    $order->get_id(),
                    $api_amount,
                    $order_total
                )
            );
            return null;
        }

        return $transaction;
    }

    /**
     * Handle a successful payment.
     *
     * @param \WC_Order $order       The WooCommerce order.
     * @param array     $transaction Verified transaction data from OEN API.
     */
    private function handle_success( \WC_Order $order, array $transaction ): void {
        $transaction_hid = $transaction['transactionHid'] ?? '';

        // Store payment metadata.
        $order->update_meta_data( '_oen_paid_at', current_time( 'c' ) );

        // Store CVS-specific metadata if present.
        $payment_info = $transaction['paymentInfo'] ?? [];
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
                __( 'OEN Payment completed (verified). Transaction: %s', 'woocommerce-oen-payment' ),
                $transaction_hid
            )
        );

        $this->log( 'Payment completed for order #' . $order->get_id() . ' (txn: ' . $transaction_hid . ')' );
    }

    /**
     * Handle a failed payment.
     *
     * @param \WC_Order $order       The WooCommerce order.
     * @param array     $transaction Verified transaction data from OEN API.
     */
    private function handle_failure( \WC_Order $order, array $transaction ): void {
        $status = sanitize_text_field( $transaction['status'] ?? 'unknown' );

        $order->update_status(
            'failed',
            sprintf(
                /* translators: %s: transaction status from API */
                __( 'OEN Payment failed (status: %s)', 'woocommerce-oen-payment' ),
                sanitize_text_field( $status )
            )
        );

        $this->log( 'Payment failed for order #' . $order->get_id() . ': status=' . sanitize_text_field( $status ) );
    }

    /**
     * Acquire a MySQL advisory lock for webhook processing.
     *
     * Prevents concurrent requests from processing the same order simultaneously.
     * Lock is automatically released when the DB connection closes (safety net).
     *
     * @param int $order_id WooCommerce order ID.
     * @return bool True if lock acquired, false if another request holds it.
     */
    private function acquire_lock( int $order_id ): bool {
        global $wpdb;

        // Non-blocking: timeout 0 means return immediately if lock is held.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $result = $wpdb->get_var(
            $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $wpdb->prefix . 'oen_webhook_' . $order_id )
        );

        // GET_LOCK returns: 1 = acquired, 0 = held by another, NULL = error.
        if ( null === $result ) {
            $this->log( 'GET_LOCK returned NULL for order #' . $order_id . ' — possible DB error' );
        }

        return '1' === $result;
    }

    /**
     * Release the MySQL advisory lock for webhook processing.
     *
     * @param int $order_id WooCommerce order ID.
     */
    private function release_lock( int $order_id ): void {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query(
            $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $wpdb->prefix . 'oen_webhook_' . $order_id )
        );
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
