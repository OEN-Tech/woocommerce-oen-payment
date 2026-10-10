<?php
/**
 * PHPStan bootstrap.
 *
 * The plugin constants are created with define() in the main plugin file, from values that
 * only exist at runtime (plugin_dir_url() and friends), so PHPStan cannot discover them by
 * reading that file. Declare their names here; the values are placeholders and are never
 * used by the plugin.
 */

define( 'OEN_PAYMENT_VERSION', '0.0.0' );
define( 'OEN_PAYMENT_PLUGIN_FILE', __DIR__ . '/../woocommerce-oen-payment.php' );
define( 'OEN_PAYMENT_PLUGIN_DIR', __DIR__ . '/../' );
define( 'OEN_PAYMENT_PLUGIN_URL', 'https://example.test/wp-content/plugins/woocommerce-oen-payment/' );
