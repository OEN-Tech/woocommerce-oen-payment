<?php

defined( 'ABSPATH' ) || exit;

class OEN_Settings extends WC_Settings_Page {

    public function __construct() {
        $this->id    = 'oen_payment';
        $this->label = __( 'OEN', 'woocommerce-oen-payment' );
        parent::__construct();
    }

    public function get_settings_for_default_section(): array {
        return [
            [
                'title' => __( 'Enable OEN method', 'woocommerce-oen-payment' ),
                'type'  => 'title',
                'id'    => 'oen_enable_section',
            ],
            [
                'title'   => __( 'Enable OEN gateway method', 'woocommerce-oen-payment' ),
                'desc'    => __( 'Enable gateway method', 'woocommerce-oen-payment' ),
                'id'      => 'oen_enabled',
                'type'    => 'checkbox',
                'default' => 'no',
            ],
            [
                'type' => 'sectionend',
                'id'   => 'oen_enable_section',
            ],
            [
                'title' => __( 'Gateway settings', 'woocommerce-oen-payment' ),
                'type'  => 'title',
                'id'    => 'oen_gateway_section',
            ],
            [
                'title'    => __( 'Order no prefix', 'woocommerce-oen-payment' ),
                'desc_tip' => __( 'Prefix prepended to the WooCommerce order ID when sent to OEN.', 'woocommerce-oen-payment' ),
                'id'       => 'oen_order_prefix',
                'type'     => 'text',
                'default'  => '',
            ],
            [
                'title'   => __( 'Display order item name', 'woocommerce-oen-payment' ),
                'desc'    => __( 'Display order item name', 'woocommerce-oen-payment' ),
                'id'      => 'oen_display_item_name',
                'type'    => 'checkbox',
                'default' => 'no',
            ],
            [
                'title'   => __( 'Show payment info in email', 'woocommerce-oen-payment' ),
                'desc'    => __( 'Enabled payment shop email', 'woocommerce-oen-payment' ),
                'id'      => 'oen_show_payment_in_email',
                'type'    => 'checkbox',
                'default' => 'no',
            ],
            [
                'type' => 'sectionend',
                'id'   => 'oen_gateway_section',
            ],
            [
                'title' => __( 'API settings', 'woocommerce-oen-payment' ),
                'type'  => 'title',
                'id'    => 'oen_api_section',
            ],
            [
                'title'   => __( 'OEN sandbox', 'woocommerce-oen-payment' ),
                'desc'    => __( 'sandbox', 'woocommerce-oen-payment' ),
                'id'      => 'oen_sandbox',
                'type'    => 'checkbox',
                'default' => 'no',
            ],
            [
                'title'   => __( 'MerchantID', 'woocommerce-oen-payment' ),
                'id'      => 'oen_merchant_id',
                'type'    => 'text',
                'default' => '',
            ],
            [
                'title'   => __( 'Secret Key', 'woocommerce-oen-payment' ),
                'id'      => 'oen_api_token',
                'type'    => 'password',
                'default' => '',
            ],
            [
                'title'    => __( 'Webhook Secret', 'woocommerce-oen-payment' ),
                'desc_tip' => __( 'HMAC secret for webhook signature verification (OenPay-Signature header). Filled in automatically when the webhook is registered; leave empty to skip signature checks for checkout events (refund events always require it).', 'woocommerce-oen-payment' ),
                'id'       => 'oen_webhook_secret',
                'type'     => 'password',
                'default'  => '',
            ],
            [
                'title'   => __( 'Re-register webhook', 'woocommerce-oen-payment' ),
                'desc'    => __( 'Re-register the OEN webhook on save (refreshes the URL and signing secret).', 'woocommerce-oen-payment' ),
                'desc_tip' => __( 'Tick this and save to (re)create the webhook on OEN. The plugin registers automatically on first save; use this after changing your site URL, MerchantID, or environment.', 'woocommerce-oen-payment' ),
                'id'      => 'oen_webhook_reregister',
                'type'    => 'checkbox',
                'default' => 'no',
            ],
            [
                'type' => 'sectionend',
                'id'   => 'oen_api_section',
            ],
        ];
    }

    /**
     * Save the settings, then register the OEN webhook if needed so the merchant
     * does not have to create it and copy the signing secret by hand.
     */
    public function save(): void {
        parent::save();
        $this->maybe_register_webhook();
    }

    /**
     * Register the Hosted Checkout webhook on first save, or refresh it on an
     * explicit re-register. Reconciles by URL (update + rotate-secret in place)
     * so it never creates duplicate webhooks or clobbers a manually configured
     * secret. Failures surface as a settings error and never block the save.
     */
    private function maybe_register_webhook(): void {
        // Consume the one-shot re-register flag up front so it can never persist
        // past a save that skips registration (e.g. gateway disabled).
        $force = 'yes' === get_option( 'oen_webhook_reregister', 'no' );
        if ( $force ) {
            update_option( 'oen_webhook_reregister', 'no' );
        }

        if ( 'yes' !== get_option( 'oen_enabled', 'no' ) ) {
            return;
        }

        if ( '' === (string) get_option( 'oen_merchant_id', '' ) || '' === (string) get_option( 'oen_api_token', '' ) ) {
            return;
        }

        $stored_id     = (string) get_option( 'oen_webhook_id', '' );
        $stored_secret = (string) get_option( 'oen_webhook_secret', '' );

        // Already registered and tracked: nothing to do on an ordinary save.
        if ( '' !== $stored_id && ! $force ) {
            return;
        }

        // Legacy/manual install (a secret was set by hand, no tracked webhook id):
        // do not silently re-create and clobber it — only act on explicit re-register.
        if ( '' === $stored_id && '' !== $stored_secret && ! $force ) {
            return;
        }

        try {
            $this->register_or_reconcile_webhook( OEN_API_Client::from_settings() );
        } catch ( \Throwable $exception ) {
            WC_Admin_Settings::add_error(
                sprintf(
                    /* translators: %s: error message */
                    __( 'OEN webhook auto-registration failed: %s', 'woocommerce-oen-payment' ),
                    $exception->getMessage()
                )
            );
        }
    }

    /**
     * Adopt and refresh an existing webhook for this site URL (update events +
     * rotate the signing secret), or create a new one when none exists. Looking up
     * by URL avoids duplicate webhooks and lets the plugin take over a webhook a
     * merchant created manually.
     */
    private function register_or_reconcile_webhook( OEN_API_Client $client ): void {
        $url    = self::webhook_url();
        $events = self::webhook_events();

        $existing_id = '';
        foreach ( $client->list_webhooks() as $webhook ) {
            if ( is_array( $webhook ) && ( $webhook['url'] ?? '' ) === $url ) {
                $existing_id = sanitize_text_field( (string) ( $webhook['id'] ?? '' ) );
                break;
            }
        }

        if ( '' !== $existing_id ) {
            $client->update_webhook( $existing_id, $url, $events );
            $rotated = $client->rotate_webhook_secret( $existing_id );
            $this->store_webhook( $existing_id, sanitize_text_field( (string) ( $rotated['secret'] ?? '' ) ), true );
            return;
        }

        $created = $client->create_webhook( $url, $events );
        $this->store_webhook(
            sanitize_text_field( (string) ( $created['id'] ?? '' ) ),
            sanitize_text_field( (string) ( $created['secret'] ?? '' ) ),
            false
        );
    }

    /**
     * Persist the registered/adopted webhook id and signing secret, or surface a
     * settings error when the response did not carry both.
     */
    private function store_webhook( string $webhook_id, string $secret, bool $reconciled ): void {
        if ( '' === $webhook_id || '' === $secret ) {
            WC_Admin_Settings::add_error(
                __( 'OEN webhook registration returned an unexpected response (no id or secret).', 'woocommerce-oen-payment' )
            );
            return;
        }

        update_option( 'oen_webhook_id', $webhook_id );
        update_option( 'oen_webhook_secret', $secret );

        WC_Admin_Settings::add_message(
            $reconciled
                ? sprintf(
                    /* translators: %s: webhook id */
                    __( 'OEN webhook updated (%s) and its signing secret refreshed.', 'woocommerce-oen-payment' ),
                    $webhook_id
                )
                : sprintf(
                    /* translators: %s: webhook id */
                    __( 'OEN webhook registered (%s). The signing secret was stored automatically.', 'woocommerce-oen-payment' ),
                    $webhook_id
                )
        );
    }

    /**
     * The site webhook endpoint OEN should call (the OEN_Webhook_Handler route).
     */
    private static function webhook_url(): string {
        return add_query_arg( 'wc-api', 'oen_payment', home_url( '/' ) );
    }

    /**
     * The event types the plugin handles, used as the webhook's enabledEvents.
     *
     * @return string[]
     */
    private static function webhook_events(): array {
        return [
            'checkout_session.completed',
            'checkout_session.failed',
            'checkout_session.expired',
            'checkout_session.cancelled',
            'refund.created',
            'refund.succeeded',
        ];
    }
}
