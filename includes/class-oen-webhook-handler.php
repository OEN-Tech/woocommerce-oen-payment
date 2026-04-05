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

        $event_type = sanitize_text_field( $payload['type'] ?? '' );
        $event_data = $payload['data'] ?? null;

        if ( '' === $event_type || ! is_array( $event_data ) || empty( $event_data['orderId'] ) ) {
            $this->log( 'Invalid webhook payload: missing orderId in event data', $raw_body );
            wp_send_json( [ 'status' => 'error', 'message' => 'Invalid payload' ], 400 );
            return;
        }

        // Sanitize external string fields to prevent HTML injection in order notes,
        // meta values, and log entries.
        $payload                    = [];
        $payload['type']            = $event_type;
        $payload['sessionId']       = sanitize_text_field( (string) ( $event_data['id'] ?? $event_data['sessionId'] ?? '' ) );
        $payload['orderId']         = sanitize_text_field( $event_data['orderId'] );
        $payload['transactionHid']  = sanitize_text_field( $event_data['transactionHid'] ?? '' );
        $payload['transactionId']   = sanitize_text_field( $event_data['transactionId'] ?? '' );
        $payload['status']          = sanitize_text_field( $event_data['status'] ?? '' );
        $payload['message']         = sanitize_text_field( $event_data['message'] ?? '' );
        $payload['paymentMethod']   = sanitize_text_field( $event_data['paymentMethod'] ?? '' );
        $payload['paymentProvider'] = sanitize_text_field( $event_data['paymentProvider'] ?? '' );

        $transaction_hid = $payload['transactionHid'];
        $session_id      = $payload['sessionId'];

        if ( empty( $transaction_hid ) && empty( $session_id ) ) {
            $this->log( 'Missing transactionHid and sessionId in webhook payload', $raw_body );
            wp_send_json( [ 'status' => 'error', 'message' => 'Missing payment reference' ], 400 );
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
            } elseif ( ! $this->is_current_attempt( $order, $payload ) ) {
                $response = [ 'status' => 'ok', 'message' => 'Stale event ignored' ];
            } else {
                // Step 2: Server-side verification — query OEN API for authoritative state.
                $verified_payment = ! empty( $session_id )
                    ? $this->verify_session( $session_id, $order )
                    : $this->verify_transaction( $transaction_hid, $order );

                if ( null === $verified_payment ) {
                    // Verification helper already logged the error.
                    $response      = [ 'status' => 'error', 'message' => 'Verification failed' ];
                    $response_code = 502;
                } elseif ( ! $this->is_current_attempt( $order, $verified_payment ) ) {
                    $response = [ 'status' => 'ok', 'message' => 'Stale event ignored' ];
                } else {
                    $resolution = self::resolve_event_action(
                        $payload['type'],
                        sanitize_text_field( (string) ( $verified_payment['status'] ?? '' ) )
                    );

                    if ( 'success' === $resolution ) {
                        $this->handle_success( $order, $verified_payment );
                    } elseif ( 'failure' === $resolution ) {
                        $this->handle_failure( $order, $verified_payment );
                    } else {
                        $this->log_event_status_mismatch( $order, $payload['type'], $verified_payment );
                        $response = [ 'status' => 'ok', 'message' => 'Event ignored' ];
                    }
                }
            }
        } finally {
            $this->release_lock( $order_id );
        }

        wp_send_json( $response, $response_code );
    }

    /**
     * Parse the hosted checkout webhook envelope and return its type plus nested data.
     *
     * @param string $raw_body Raw request body.
     * @return array<string, mixed>
     */
    private function parse_webhook_payload( string $raw_body ): array {
        $parser = new OEN_Webhook_Parser( get_option( 'oen_webhook_secret', '' ) );

        return $parser->parse( $raw_body, $this->get_signature_header() );
    }

    /**
     * Ignore events that do not match the order's current hosted checkout attempt.
     *
     * @param \WC_Order              $order   The WooCommerce order.
     * @param array<string, mixed>   $payload Incoming webhook or verified transaction payload.
     */
    private function is_current_attempt( \WC_Order $order, array $payload ): bool {
        $stored_session_id      = sanitize_text_field( (string) $order->get_meta( '_oen_session_id' ) );
        $stored_transaction_hid = sanitize_text_field( (string) $order->get_meta( '_oen_transaction_hid' ) );
        $incoming_session_id    = sanitize_text_field( (string) ( $payload['sessionId'] ?? '' ) );
        $incoming_transaction   = sanitize_text_field( (string) ( $payload['transactionHid'] ?? '' ) );
        $mismatch_reason        = self::detect_attempt_mismatch(
            $stored_session_id,
            $stored_transaction_hid,
            [
                'sessionId'      => $incoming_session_id,
                'transactionHid' => $incoming_transaction,
            ]
        );

        if ( null === $mismatch_reason ) {
            return true;
        }

        $this->log(
            sprintf(
                'Ignoring stale webhook for order #%d: %s',
                $order->get_id(),
                $mismatch_reason
            )
        );

        return false;
    }

    /**
     * Resolve whether an event/status pair should transition the order.
     *
     * @return 'success'|'failure'|'ignore'
     */
    public static function resolve_event_action( string $event_type, string $verified_status ): string {
        $event_type      = sanitize_text_field( $event_type );
        $verified_status = sanitize_text_field( $verified_status );

        if ( self::is_success_event( $event_type ) ) {
            return self::is_success_status( $verified_status ) ? 'success' : 'ignore';
        }

        if ( self::is_failure_event( $event_type ) ) {
            return self::is_failure_status( $verified_status ) ? 'failure' : 'ignore';
        }

        return 'ignore';
    }

    /**
     * Detect whether the incoming attempt mismatches the current stored attempt.
     *
     * @param array<string, mixed> $payload Incoming webhook or verified transaction payload.
     * @return string|null Mismatch reason when stale, or null when the attempt matches.
     */
    public static function detect_attempt_mismatch(
        string $stored_session_id,
        string $stored_transaction_hid,
        array $payload
    ): ?string {
        $stored_session_id      = sanitize_text_field( $stored_session_id );
        $stored_transaction_hid = sanitize_text_field( $stored_transaction_hid );
        $incoming_session_id    = sanitize_text_field( (string) ( $payload['sessionId'] ?? '' ) );
        $incoming_transaction   = sanitize_text_field( (string) ( $payload['transactionHid'] ?? '' ) );

        if ( '' !== $stored_session_id ) {
            if ( '' === $incoming_session_id ) {
                return sprintf( 'missing sessionId, expected=%s', $stored_session_id );
            }

            if ( $stored_session_id !== $incoming_session_id ) {
                return sprintf( 'sessionId=%s, expected=%s', $incoming_session_id, $stored_session_id );
            }
        }

        if ( '' !== $stored_transaction_hid && '' !== $incoming_transaction && $stored_transaction_hid !== $incoming_transaction ) {
            return sprintf( 'transactionHid=%s, expected=%s', $incoming_transaction, $stored_transaction_hid );
        }

        return null;
    }

    /**
     * Determine whether the webhook event represents a successful charge completion.
     */
    private static function is_success_event( string $event_type ): bool {
        return in_array(
            $event_type,
            [
                'checkout_session.completed',
            ],
            true
        );
    }

    /**
     * Determine whether the webhook event should mark the current attempt as failed.
     */
    private static function is_failure_event( string $event_type ): bool {
        return in_array(
            $event_type,
            [
                'checkout_session.failed',
                'checkout_session.expired',
                'checkout_session.cancelled',
            ],
            true
        );
    }

    /**
     * Determine whether the verified transaction status is a success terminal state.
     */
    private static function is_success_status( string $status ): bool {
        return in_array( $status, [ 'completed', 'charged' ], true );
    }

    /**
     * Determine whether the verified transaction status is a failure terminal state.
     */
    private static function is_failure_status( string $status ): bool {
        return in_array(
            $status,
            [
                'failed',
                'expired',
                'cancelled',
            ],
            true
        );
    }

    /**
     * Log an ignored event whose type does not align with the verified status.
     *
     * @param \WC_Order            $order       The WooCommerce order.
     * @param string               $event_type  Parsed webhook event type.
     * @param array<string, mixed> $transaction Verified transaction payload.
     */
    private function log_event_status_mismatch( \WC_Order $order, string $event_type, array $transaction ): void {
        $this->log(
            sprintf(
                'Ignoring webhook for order #%d: type=%s, verified_status=%s',
                $order->get_id(),
                sanitize_text_field( $event_type ),
                sanitize_text_field( (string) ( $transaction['status'] ?? 'unknown' ) )
            )
        );
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

        if ( ! $this->validate_verified_payment( $transaction, $order, 'transaction' ) ) {
            return null;
        }

        return $transaction;
    }

    /**
     * Verify a hosted checkout session via the OEN API and normalize it into
     * the same authoritative payment shape used by transaction verification.
     *
     * @param string    $session_id The OEN hosted checkout session ID.
     * @param \WC_Order $order      The WooCommerce order.
     * @return array|null Verified payment data, or null on failure.
     */
    private function verify_session( string $session_id, \WC_Order $order ): ?array {
        try {
            $api     = OEN_API_Client::from_settings();
            $session = $api->get_session( $session_id );
        } catch ( \Throwable $e ) {
            $this->log(
                sprintf( 'Session verification failed for order #%d: %s', $order->get_id(), $e->getMessage() )
            );
            return null;
        }

        $response_session_id = sanitize_text_field( (string) ( $session['id'] ?? $session['sessionId'] ?? '' ) );
        if ( '' !== $response_session_id && $response_session_id !== $session_id ) {
            $this->log(
                sprintf(
                    'Session ID mismatch for order #%d: api=%s, expected=%s',
                    $order->get_id(),
                    $response_session_id,
                    $session_id
                )
            );
            return null;
        }

        $transaction = is_array( $session['transaction'] ?? null ) ? $session['transaction'] : [];
        $payment_info = [];
        if ( is_array( $transaction['paymentInfo'] ?? null ) ) {
            $payment_info = $transaction['paymentInfo'];
        } elseif ( is_array( $session['paymentInfo'] ?? null ) ) {
            $payment_info = $session['paymentInfo'];
        }

        $verified_payment = [
            'sessionId'      => '' !== $response_session_id ? $response_session_id : $session_id,
            'transactionHid' => sanitize_text_field( (string) ( $transaction['transactionHid'] ?? $session['transactionHid'] ?? '' ) ),
            'transactionId'  => sanitize_text_field( (string) ( $transaction['transactionId'] ?? $session['transactionId'] ?? $transaction['id'] ?? '' ) ),
            'orderId'        => sanitize_text_field( (string) ( $session['orderId'] ?? $transaction['orderId'] ?? '' ) ),
            'status'         => sanitize_text_field( (string) ( $transaction['status'] ?? $session['status'] ?? '' ) ),
            'amount'         => $transaction['amount'] ?? $session['amount'] ?? null,
            'paymentInfo'    => $payment_info,
        ];

        if ( ! $this->validate_verified_payment( $verified_payment, $order, 'session' ) ) {
            return null;
        }

        return $verified_payment;
    }

    /**
     * Validate that verified payment data is still bound to the current order.
     *
     * @param array<string, mixed> $verified_payment Verified payment data from OEN.
     * @param \WC_Order            $order            The WooCommerce order.
     * @param string               $source           Verification source label for logs.
     */
    private function validate_verified_payment( array $verified_payment, \WC_Order $order, string $source ): bool {
        $api_order_id = sanitize_text_field( (string) ( $verified_payment['orderId'] ?? '' ) );
        $expected_id  = sanitize_text_field( (string) $order->get_meta( '_oen_order_id' ) );

        if ( '' === $api_order_id || $api_order_id !== $expected_id ) {
            $this->log(
                sprintf(
                    'Order ID mismatch during %1$s verification for order #%2$d: api=%3$s, expected=%4$s',
                    $source,
                    $order->get_id(),
                    $api_order_id ?: 'missing',
                    $expected_id
                )
            );
            return false;
        }

        if ( array_key_exists( 'amount', $verified_payment ) && '' !== (string) $verified_payment['amount'] ) {
            $api_amount  = intval( $verified_payment['amount'] );
            $order_total = intval( $order->get_total() );

            if ( $api_amount !== $order_total ) {
                $this->log(
                    sprintf(
                        'Amount mismatch during %1$s verification for order #%2$d: api=%3$d, order=%4$d',
                        $source,
                        $order->get_id(),
                        $api_amount,
                        $order_total
                    )
                );
                return false;
            }
        }

        return true;
    }

    /**
     * Handle a successful payment.
     *
     * @param \WC_Order $order       The WooCommerce order.
     * @param array     $transaction Verified transaction data from OEN API.
     */
    private function handle_success( \WC_Order $order, array $transaction ): void {
        $transaction_hid = $transaction['transactionHid'] ?? '';
        $status          = sanitize_text_field( $transaction['status'] ?? '' );

        if ( ! in_array( $status, [ 'completed', 'charged' ], true ) ) {
            $this->log(
                sprintf(
                    'Skipping success transition for order #%d because verified status is %s',
                    $order->get_id(),
                    $status ?: 'unknown'
                )
            );
            return;
        }

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
