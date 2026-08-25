<?php

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates the two paths that can mirror an OEN refund onto a WooCommerce order.
 *
 *  1. the merchant refunds from the WooCommerce order screen, which calls
 *     WC_Gateway_OEN::process_refund() and lets WooCommerce create the WC refund; and
 *  2. the refund.succeeded webhook, which mirrors refunds started anywhere else.
 *
 * Both converge on the same OEN refund id, so without coordination the webhook would
 * create a second WC refund for one the merchant already made, doubling
 * total_refunded on the order.
 *
 * Two records are needed, because the refund id alone cannot close the race: the
 * backend emits refund.succeeded while it is still answering the refund request, so
 * the webhook can arrive BEFORE process_refund() learns the refund id.
 *
 *  - an in-progress claim, set before the refund request goes out, tells the webhook
 *    that the admin path owns this refund; and
 *  - the list of mirrored refund ids, which makes both paths idempotent on retries
 *    and on the paired refund.created/refund.succeeded events.
 */
final class OEN_Refund_Registry {

    /**
     * Order meta key holding the list of mirrored OEN refund ids.
     */
    public const META_KEY = '_oen_processed_refund_ids';

    /**
     * Order meta key marking that an admin-initiated refund is in flight.
     */
    public const IN_PROGRESS_META_KEY = '_oen_refund_in_progress';

    /**
     * Seconds an in-progress claim stays valid. A request that dies mid-refund must not
     * block webhook mirroring forever.
     */
    public const IN_PROGRESS_TTL = 300;

    /**
     * Claim this order's next refund for the admin-initiated path, before the refund
     * request is sent. The webhook checks this and leaves the WC refund to WooCommerce.
     *
     * @param \WC_Order $order The WooCommerce order.
     */
    public static function begin( \WC_Order $order ): void {
        $order->update_meta_data( self::IN_PROGRESS_META_KEY, (string) time() );
        $order->save();
    }

    /**
     * Release the claim without recording a refund id — used when the refund failed.
     *
     * @param \WC_Order $order The WooCommerce order.
     */
    public static function end( \WC_Order $order ): void {
        $order->delete_meta_data( self::IN_PROGRESS_META_KEY );
        $order->save();
    }

    /**
     * Whether an admin-initiated refund is currently in flight for this order.
     *
     * @param \WC_Order $order The WooCommerce order.
     */
    public static function is_in_progress( \WC_Order $order ): bool {
        $claimed_at = (int) $order->get_meta( self::IN_PROGRESS_META_KEY );

        if ( $claimed_at <= 0 ) {
            return false;
        }

        return ( time() - $claimed_at ) < self::IN_PROGRESS_TTL;
    }

    /**
     * Whether this OEN refund id has already been mirrored onto the order.
     *
     * @param \WC_Order $order     The WooCommerce order.
     * @param string    $refund_id OEN refund id.
     */
    public static function is_processed( \WC_Order $order, string $refund_id ): bool {
        if ( '' === $refund_id ) {
            return false;
        }

        return in_array( $refund_id, self::get_ids( $order ), true );
    }

    /**
     * Record that an OEN refund id has been mirrored, for idempotency on retries,
     * on the paired refund.created/refund.succeeded events, and across the
     * admin-initiated and webhook-initiated paths.
     *
     * @param \WC_Order $order     The WooCommerce order.
     * @param string    $refund_id OEN refund id.
     */
    public static function mark_processed( \WC_Order $order, string $refund_id ): void {
        if ( '' === $refund_id ) {
            return;
        }

        $ids   = self::get_ids( $order );
        $ids[] = $refund_id;

        $order->update_meta_data( self::META_KEY, array_values( array_unique( $ids ) ) );
        $order->delete_meta_data( self::IN_PROGRESS_META_KEY );
        $order->save();
    }

    /**
     * @param \WC_Order $order The WooCommerce order.
     * @return array<int, string> Recorded OEN refund ids.
     */
    private static function get_ids( \WC_Order $order ): array {
        $ids = $order->get_meta( self::META_KEY );

        return is_array( $ids ) ? $ids : [];
    }
}
