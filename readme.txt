=== WooCommerce OEN Payment Gateway ===
Contributors: oentechnology
Tags: woocommerce, payment, gateway, oen, credit card, cvs, taiwan
Requires at least: 6.1
Tested up to: 6.8
Requires PHP: 8.1
WC requires at least: 8.2
WC tested up to: 9.6
Stable tag: 1.0.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

OEN 金流付款外掛 — Accept credit card and convenience store payments via OEN Payment.

== Description ==

Integrates OEN Payment (應援金流) with WooCommerce, enabling merchants to accept:

* **Credit Card** — Redirect to OEN's hosted checkout for credit card payment
* **CVS (Convenience Store)** — Generate payment codes for convenience store payment

Features:

* Separate enable/disable toggle for each payment method
* Configurable order number prefix
* Optional product detail line items sent to OEN
* Payment information in WooCommerce order emails
* Sandbox/production environment switching
* Traditional Chinese (zh_TW) and English language support
* WooCommerce HPOS compatible

== Installation ==

1. Upload the `woocommerce-oen-payment` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu in WordPress
3. Go to WooCommerce > Settings > OEN to configure your MerchantID and API Token
4. Go to WooCommerce > Settings > Payments to enable OEN Credit and/or OEN Cvs
5. Configure your webhook URL in OEN CRM backend: `https://yoursite.com/?wc-api=oen_payment`

== Changelog ==

= 1.0.0 =
* Initial release
* Credit card payment via OEN hosted checkout
* CVS (convenience store) payment via OEN hosted checkout
* Webhook handler for async payment confirmation
* Payment info in order emails
* zh_TW Traditional Chinese translations
