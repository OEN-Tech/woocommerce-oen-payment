<?php

defined( 'ABSPATH' ) || exit;

/**
 * Handles OEN payment error display on the checkout page.
 *
 * When OEN redirects to failureUrl with ?payment_error={code},
 * this handler maps the error code to a localized message and
 * displays it as a WooCommerce notice on the checkout page.
 */
class OEN_Error_Handler {

    public function __construct() {
        add_action( 'woocommerce_before_checkout_form', [ $this, 'display_payment_error' ] );
    }

    /**
     * Check for payment_error query param and display as WC notice.
     */
    public function display_payment_error(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( empty( $_GET['payment_error'] ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $error_code = sanitize_text_field( wp_unslash( $_GET['payment_error'] ) );
        $message    = $this->get_error_message( $error_code );

        wc_add_notice( $message, 'error' );
    }

    /**
     * Map an OEN error code to a localized message.
     *
     * @param string $code The OEN error code.
     * @return string Localized error message.
     */
    private function get_error_message( string $code ): string {
        $messages = [
            'T0001' => __( 'Transaction failed, please try again.', 'woocommerce-oen-payment' ),
            'T0002' => __( 'CVV/CVC verification error.', 'woocommerce-oen-payment' ),
            'T0003' => __( 'Card expired.', 'woocommerce-oen-payment' ),
            'T0004' => __( 'Insufficient credit limit.', 'woocommerce-oen-payment' ),
            'T0005' => __( 'Payment refused.', 'woocommerce-oen-payment' ),
            'V0001' => __( 'Request error, please contact merchant.', 'woocommerce-oen-payment' ),
            'V0002' => __( 'Invalid transaction state.', 'woocommerce-oen-payment' ),
            'F0001' => __( 'System error, please try again.', 'woocommerce-oen-payment' ),
        ];

        return $messages[ $code ] ?? sprintf(
            /* translators: %s: error code */
            __( 'Payment error (%s). Please try again.', 'woocommerce-oen-payment' ),
            $code
        );
    }
}
