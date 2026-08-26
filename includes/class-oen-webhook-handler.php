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

        // Refund events correlate by sessionId (their payload has no orderId), so
        // they are routed before the checkout-session orderId validation below.
        if ( '' !== $event_type && str_starts_with( $event_type, 'refund.' ) ) {
            if ( ! is_array( $event_data ) ) {
                $this->log( 'Invalid refund webhook payload: missing data', $raw_body );
                wp_send_json( [ 'status' => 'error', 'message' => 'Invalid payload' ], 400 );
                return;
            }
            $this->handle_refund_event( $event_type, $event_data, $raw_body );
            return;
        }

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
                        self::get_verified_payment_status( $verified_payment )
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
     * Process a refund.* webhook event and mirror it into a WooCommerce refund.
     *
     * Refund events correlate to the order by the Hosted Checkout session id
     * (their payload carries no orderId). A synchronous (card) refund emits both
     * refund.created and refund.succeeded with the same data, so the WooCommerce
     * refund is created only on the terminal refund.succeeded event and is made
     * idempotent on the refund id to avoid double refunds.
     *
     * @param string               $event_type Webhook event type (refund.*).
     * @param array<string, mixed> $event_data Refund event data object.
     * @param string               $raw_body   Raw request body for logging.
     */
    private function handle_refund_event( string $event_type, array $event_data, string $raw_body ): void {
        // Refunds mutate money directly and, unlike the checkout-session path, have
        // no server-side OEN re-verification fallback — their authenticity rests
        // entirely on the webhook signature. Signature verification is skipped when
        // no secret is configured, so refund events MUST fail closed without one to
        // avoid acting on a forged, unsigned payload.
        if ( '' === sanitize_text_field( (string) get_option( 'oen_webhook_secret', '' ) ) ) {
            $this->log( 'Refund webhook rejected: a configured webhook secret is required to verify refund events', $raw_body );
            wp_send_json(
                [ 'status' => 'error', 'message' => 'Webhook signature verification is required for refund events' ],
                401
            );
            return;
        }

        $session_id = sanitize_text_field( (string) ( $event_data['sessionId'] ?? '' ) );
        $refund_id  = sanitize_text_field( (string) ( $event_data['id'] ?? '' ) );
        $status     = sanitize_text_field( (string) ( $event_data['status'] ?? '' ) );
        $reason     = sanitize_text_field( (string) ( $event_data['reason'] ?? '' ) );

        if ( '' === $session_id || '' === $refund_id ) {
            $this->log( 'Refund webhook missing sessionId or refund id', $raw_body );
            wp_send_json( [ 'status' => 'error', 'message' => 'Invalid refund payload' ], 400 );
            return;
        }

        // Only the terminal success event creates a WooCommerce refund. refund.created
        // fires alongside refund.succeeded for synchronous refunds — acknowledge it
        // without acting so the two events cannot create duplicate refunds.
        if ( 'refund.succeeded' !== $event_type ) {
            wp_send_json( [ 'status' => 'ok', 'message' => 'Refund event acknowledged' ], 200 );
            return;
        }

        if ( 'refunded' !== $status ) {
            $this->log(
                sprintf( 'refund.succeeded for %s carried non-terminal status: %s', $refund_id, $status ?: 'unknown' )
            );
            wp_send_json( [ 'status' => 'ok', 'message' => 'Refund not in terminal state' ], 200 );
            return;
        }

        $order = $this->find_order_by_session_id( $session_id );

        if ( ! $order ) {
            $this->log( 'Refund webhook: no order found for sessionId ' . $session_id );
            wp_send_json( [ 'status' => 'error', 'message' => 'Order not found' ], 404 );
            return;
        }

        $order_id = $order->get_id();

        if ( ! $this->acquire_lock( $order_id ) ) {
            $this->log( 'Refund: order #' . $order_id . ' is being processed by another request.' );
            wp_send_json( [ 'status' => 'error', 'message' => 'Processing in progress' ], 409 );
            return;
        }

        $response      = [ 'status' => 'ok' ];
        $response_code = 200;

        try {
            $order = wc_get_order( $order_id );

            if ( ! $order ) {
                $response      = [ 'status' => 'error', 'message' => 'Order not found' ];
                $response_code = 404;
            } elseif ( OEN_Refund_Registry::is_processed( $order, $refund_id ) ) {
                $this->log( 'Refund ' . $refund_id . ' already processed for order #' . $order_id );
                $response = [ 'status' => 'ok', 'message' => 'Refund already processed' ];
            } elseif ( OEN_Refund_Registry::is_in_progress( $order ) ) {
                // The merchant started this refund from the order screen. The backend
                // emits refund.succeeded while it is still answering that request, so
                // this event can arrive before process_refund() learns the refund id.
                // WooCommerce creates the WC refund on that path — mirroring it here
                // too would double total_refunded. Claim the id so a webhook retry
                // cannot mirror it later either.
                OEN_Refund_Registry::mark_processed( $order, $refund_id );
                $this->log(
                    'Refund ' . $refund_id . ' left to the admin refund path for order #' . $order_id
                );
                $response = [ 'status' => 'ok', 'message' => 'Refund handled by the admin path' ];
            } else {
                $amount = intval( $event_data['amount'] ?? 0 );

                if ( $amount <= 0 ) {
                    $this->log( 'Refund webhook: non-positive amount for refund ' . $refund_id );
                    $response      = [ 'status' => 'error', 'message' => 'Invalid refund amount' ];
                    $response_code = 400;
                } elseif ( $this->create_wc_refund( $order, $amount, $reason, $refund_id ) ) {
                    OEN_Refund_Registry::mark_processed( $order, $refund_id );
                    $response = [ 'status' => 'ok' ];
                } else {
                    $response      = [ 'status' => 'error', 'message' => 'Refund creation failed' ];
                    $response_code = 502;
                }
            }
        } finally {
            $this->release_lock( $order_id );
        }

        wp_send_json( $response, $response_code );
    }

    /**
     * Create a WooCommerce refund mirroring the OEN refund. Amount is in the WC
     * order currency (refund events carry no currency; the order's is authoritative).
     *
     * @param \WC_Order $order     The WooCommerce order.
     * @param int       $amount    Refund amount in major currency units.
     * @param string    $reason    Refund reason, if any.
     * @param string    $refund_id OEN refund id for the order note.
     */
    private function create_wc_refund( \WC_Order $order, int $amount, string $reason, string $refund_id ): bool {
        $note = sprintf(
            /* translators: %s: OEN refund id */
            __( 'OEN refund %s', 'woocommerce-oen-payment' ),
            $refund_id
        );

        $result = wc_create_refund( [
            'order_id' => $order->get_id(),
            'amount'   => $amount,
            'reason'   => '' !== $reason ? $reason : $note,
        ] );

        if ( is_wp_error( $result ) ) {
            $this->log(
                sprintf(
                    'wc_create_refund failed for order #%1$d refund %2$s: %3$s',
                    $order->get_id(),
                    $refund_id,
                    $result->get_error_message()
                )
            );
            return false;
        }

        $order->add_order_note( $note );
        $this->log(
            sprintf( 'Created WC refund for order #%1$d amount %2$d (OEN refund %3$s)', $order->get_id(), $amount, $refund_id )
        );

        return true;
    }

    /**
     * Find a WC order by the Hosted Checkout session id stored in meta.
     *
     * @param string $session_id The OEN hosted checkout session id.
     * @return \WC_Order|null
     */
    private function find_order_by_session_id( string $session_id ): ?\WC_Order {
        $orders = wc_get_orders( [
            'meta_key'   => '_oen_session_id',
            'meta_value' => $session_id,
            'limit'      => 1,
        ] );

        return $orders[0] ?? null;
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
     * Normalize the authoritative payment status from a verified Hosted Checkout session payload.
     *
     * The Hosted Checkout session API returns the lifecycle status at the top
     * level (`completed`|`failed`|`expired`|`cancelled`). A nested
     * `transaction.status` is accepted only as a fallback for forward
     * compatibility with future payload shapes.
     *
     * @param array<string, mixed> $session Verified Hosted Checkout session payload.
     */
    public static function normalize_verified_session_status( array $session ): string {
        $status = sanitize_text_field( (string) ( $session['status'] ?? '' ) );

        if ( '' !== $status ) {
            return $status;
        }

        $transaction = is_array( $session['transaction'] ?? null ) ? $session['transaction'] : [];

        return sanitize_text_field( (string) ( $transaction['status'] ?? '' ) );
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

            return null;
        }

        if ( '' !== $incoming_session_id && ( '' === $stored_transaction_hid || '' === $incoming_transaction ) ) {
            return sprintf(
                'unverifiable sessionId=%s without stored session binding or matching transactionHid',
                $incoming_session_id
            );
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
     * Normalize the status value from verified payment data before order transitions.
     *
     * @param array<string, mixed> $verified_payment Verified payment data.
     */
    private static function get_verified_payment_status( array $verified_payment ): string {
        $status = sanitize_text_field( (string) ( $verified_payment['status'] ?? '' ) );

        if ( '' !== $status ) {
            return $status;
        }

        return self::normalize_verified_session_status( $verified_payment );
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
                self::get_verified_payment_status( $transaction ) ?: 'unknown'
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
            'status'         => self::normalize_verified_session_status( $session ),
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

        if ( ! array_key_exists( 'amount', $verified_payment ) || '' === sanitize_text_field( (string) $verified_payment['amount'] ) ) {
            $this->log(
                sprintf(
                    'Missing amount during %1$s verification for order #%2$d',
                    $source,
                    $order->get_id()
                )
            );
            return false;
        }

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
        $transaction_id  = $transaction['transactionId'] ?? '';
        $status          = self::get_verified_payment_status( $transaction );

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
        if ( '' !== sanitize_text_field( (string) $transaction_hid ) ) {
            $order->update_meta_data( '_oen_transaction_hid', sanitize_text_field( (string) $transaction_hid ) );
        }
        if ( '' !== sanitize_text_field( (string) $transaction_id ) ) {
            $order->update_meta_data( '_oen_transaction_id', sanitize_text_field( (string) $transaction_id ) );
        }

        // Store CVS-specific metadata if present.
        $payment_info = is_array( $transaction['paymentInfo'] ?? null ) ? $transaction['paymentInfo'] : [];
        OEN_Payment_Info::apply( $order, $payment_info );

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
        $status = self::get_verified_payment_status( $transaction );

        if ( '' === $status ) {
            $status = 'unknown';
        }

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
            $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $this->get_lock_name( $order_id ) )
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
            $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->get_lock_name( $order_id ) )
        );
    }

    /**
     * Build the shared advisory lock name for a specific order.
     *
     * @param int $order_id WooCommerce order ID.
     */
    private function get_lock_name( int $order_id ): string {
        global $wpdb;

        return $wpdb->prefix . 'oen_order_' . $order_id;
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
