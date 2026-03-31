<?php

defined( 'ABSPATH' ) || exit;

/**
 * OEN Convenience Store (CVS) payment gateway.
 *
 * Redirects to OEN's hosted checkout for CVS payment code generation.
 * Passes allowedPaymentMethods: ["cvs"] to restrict to CVS only.
 */
class WC_Gateway_OEN_CVS extends WC_Gateway_OEN {

    public function __construct() {
        $this->id                 = 'oen_cvs';
        $this->method_title       = __( 'OEN Cvs', 'woocommerce-oen-payment' );
        $this->method_description = __( 'Pay at convenience store via OEN Payment.', 'woocommerce-oen-payment' );
        $this->payment_method_type = 'cvs';
        $this->icon               = OEN_PAYMENT_PLUGIN_URL . 'assets/images/oen-cvs.png';

        parent::__construct();
    }

    /**
     * Restrict to CVS payment method only.
     */
    protected function get_allowed_payment_methods(): array {
        return [ 'cvs' ];
    }
}
