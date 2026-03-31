<?php

defined( 'ABSPATH' ) || exit;

/**
 * OEN Credit Card payment gateway.
 *
 * Redirects to OEN's hosted checkout for credit card payment.
 * Does not pass allowedPaymentMethods — credit card is OEN's default.
 */
class WC_Gateway_OEN_Credit extends WC_Gateway_OEN {

    public function __construct() {
        $this->id                 = 'oen_credit';
        $this->method_title       = __( 'OEN Credit', 'woocommerce-oen-payment' );
        $this->method_description = __( 'Pay with credit card via OEN Payment.', 'woocommerce-oen-payment' );
        $this->payment_method_type = 'card';
        $this->icon               = OEN_PAYMENT_PLUGIN_URL . 'assets/images/oen-credit.png';

        parent::__construct();
    }

    // Credit card is OEN's default — no allowedPaymentMethods override needed.
}
