<?php

defined( 'ABSPATH' ) || exit;

/**
 * Adds OEN payment information to WooCommerce order emails.
 *
 * When "Show payment info in email" is enabled in OEN settings,
 * appends transaction HID, payment time (for paid orders) or
 * CVS payment code/expiration (for pending CVS orders) after
 * the order table in customer emails.
 */
class OEN_Email_Handler {

    public function __construct() {
        add_action( 'woocommerce_email_after_order_table', [ $this, 'add_payment_info' ], 10, 4 );
    }

    /**
     * Add OEN payment info after the order table in emails.
     *
     * @param \WC_Order $order         The order object.
     * @param bool      $sent_to_admin Whether this is an admin email.
     * @param bool      $plain_text    Whether this is a plain text email.
     * @param \WC_Email $email         The email object.
     */
    public function add_payment_info( \WC_Order $order, bool $sent_to_admin, bool $plain_text, $email ): void {
        if ( 'yes' !== get_option( 'oen_show_payment_in_email', 'no' ) ) {
            return;
        }

        // Only show for OEN payment methods.
        $payment_method = $order->get_payment_method();
        if ( ! in_array( $payment_method, [ 'oen_credit', 'oen_cvs', 'oen_atm' ], true ) ) {
            return;
        }

        $transaction_hid = $order->get_meta( '_oen_transaction_hid' );
        if ( empty( $transaction_hid ) ) {
            return;
        }

        if ( $plain_text ) {
            $this->render_plain_text( $order, $transaction_hid );
        } else {
            $this->render_html( $order, $transaction_hid );
        }
    }

    /**
     * Render HTML email payment info.
     */
    private function render_html( \WC_Order $order, string $transaction_hid ): void {
        echo '<h2>' . esc_html__( 'Payment Information', 'woocommerce-oen-payment' ) . '</h2>';
        echo '<table cellspacing="0" cellpadding="6" border="1" style="border-collapse:collapse; width:100%;">';

        // Always show transaction HID.
        echo '<tr>';
        echo '<th style="text-align:left;">' . esc_html__( 'Transaction ID', 'woocommerce-oen-payment' ) . '</th>';
        echo '<td>' . esc_html( $transaction_hid ) . '</td>';
        echo '</tr>';

        // Show payment time if paid.
        $paid_at = $order->get_meta( '_oen_paid_at' );
        if ( $paid_at ) {
            echo '<tr>';
            echo '<th style="text-align:left;">' . esc_html__( 'Payment Time', 'woocommerce-oen-payment' ) . '</th>';
            echo '<td>' . esc_html( $paid_at ) . '</td>';
            echo '</tr>';
        }

        // Show CVS info if pending CVS payment.
        $cvs_code = $order->get_meta( '_oen_cvs_code' );
        if ( $cvs_code ) {
            $cvs_name    = $order->get_meta( '_oen_cvs_name' );
            $cvs_expired = $order->get_meta( '_oen_cvs_expired_at' );

            if ( $cvs_name ) {
                echo '<tr>';
                echo '<th style="text-align:left;">' . esc_html__( 'Store', 'woocommerce-oen-payment' ) . '</th>';
                echo '<td>' . esc_html( $cvs_name ) . '</td>';
                echo '</tr>';
            }

            echo '<tr>';
            echo '<th style="text-align:left;">' . esc_html__( 'Payment Code', 'woocommerce-oen-payment' ) . '</th>';
            echo '<td><strong>' . esc_html( $cvs_code ) . '</strong></td>';
            echo '</tr>';

            if ( $cvs_expired ) {
                echo '<tr>';
                echo '<th style="text-align:left;">' . esc_html__( 'Payment Deadline', 'woocommerce-oen-payment' ) . '</th>';
                echo '<td>' . esc_html( $cvs_expired ) . '</td>';
                echo '</tr>';
            }
        }

        echo '</table>';
    }

    /**
     * Render plain text email payment info.
     */
    private function render_plain_text( \WC_Order $order, string $transaction_hid ): void {
        echo "\n" . __( 'Payment Information', 'woocommerce-oen-payment' ) . "\n";
        echo str_repeat( '-', 40 ) . "\n";

        /* translators: %s: transaction HID */
        printf( __( 'Transaction ID: %s', 'woocommerce-oen-payment' ) . "\n", $transaction_hid );

        $paid_at = $order->get_meta( '_oen_paid_at' );
        if ( $paid_at ) {
            /* translators: %s: payment timestamp */
            printf( __( 'Payment Time: %s', 'woocommerce-oen-payment' ) . "\n", $paid_at );
        }

        $cvs_code = $order->get_meta( '_oen_cvs_code' );
        if ( $cvs_code ) {
            $cvs_name    = $order->get_meta( '_oen_cvs_name' );
            $cvs_expired = $order->get_meta( '_oen_cvs_expired_at' );

            if ( $cvs_name ) {
                /* translators: %s: convenience store name */
                printf( __( 'Store: %s', 'woocommerce-oen-payment' ) . "\n", $cvs_name );
            }
            /* translators: %s: CVS payment code */
            printf( __( 'Payment Code: %s', 'woocommerce-oen-payment' ) . "\n", $cvs_code );
            if ( $cvs_expired ) {
                /* translators: %s: payment deadline */
                printf( __( 'Payment Deadline: %s', 'woocommerce-oen-payment' ) . "\n", $cvs_expired );
            }
        }

        echo "\n";
    }
}
