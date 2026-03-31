<?php

defined( 'ABSPATH' ) || exit;

/**
 * OEN ATM (Virtual Account) payment gateway.
 *
 * WIP — This gateway is not registered in woocommerce_payment_gateways.
 * It exists as a stub for future implementation.
 */
class WC_Gateway_OEN_ATM extends WC_Gateway_OEN {

    public function __construct() {
        $this->id                 = 'oen_atm';
        $this->method_title       = __( 'OEN ATM', 'woocommerce-oen-payment' );
        $this->method_description = __( 'Pay via ATM virtual account transfer via OEN Payment.', 'woocommerce-oen-payment' );
        $this->payment_method_type = 'atm';

        parent::__construct();
    }

    /**
     * ATM is WIP — always unavailable.
     */
    public function is_available(): bool {
        return false;
    }

    protected function get_allowed_payment_methods(): array {
        return [ 'atm' ];
    }
}
