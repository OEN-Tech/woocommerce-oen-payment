=== WooCommerce OEN Payment Gateway ===
Contributors: oentechnology
Tags: woocommerce, payment, gateway, oen, credit card, cvs, taiwan
Requires at least: 6.1
Tested up to: 6.8
Requires PHP: 8.1
WC requires at least: 8.2
WC tested up to: 11.0
Stable tag: 1.0.3
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Accept credit card and convenience store (CVS) payments in WooCommerce through OEN Payment (應援金流).

== Description ==

OEN Payment Gateway connects your WooCommerce store to OEN Payment (應援金流) by Oen Tech. Buyers pay on the OEN hosted payment page. The plugin confirms each payment with OEN and then updates the WooCommerce order.

Payment methods:

* **OEN Credit** — credit card on the OEN hosted payment page. Full and partial refunds from the WooCommerce order screen.
* **OEN Cvs** — convenience store payment code. The code is shown to the buyer, on the admin order screen, and optionally in order emails. Refunds are not supported.

Features:

* Registers its webhook with OEN automatically when you save the settings. You do not set up anything in the OEN back office.
* Confirms every payment notification with OEN before it changes an order. The order number and the amount must match.
* Reuses the same OEN payment when a buyer submits checkout again. When the buyer switches between OEN Credit and OEN Cvs, the plugin creates a new OEN payment and cancels the old one.
* Switch between the OEN test and production environments.
* Classic checkout and Cart & Checkout Blocks. Compatible with WooCommerce HPOS.
* English interface with a Traditional Chinese (zh_TW) translation.

Requirements:

* Store currency New Taiwan Dollar (TWD) with 0 decimals. The plugin sends whole TWD amounts and does not convert currencies.
* A site URL that OEN can reach from the internet. OEN sends payment notifications to it.
* WP-Cron / Action Scheduler running. The plugin uses it to fetch CVS payment codes.

Setup guide with screenshots (Traditional Chinese): https://github.com/OEN-Tech/woocommerce-oen-payment/blob/main/docs/setup-guide.md

== Installation ==

1. Upload the `woocommerce-oen-payment` folder to `/wp-content/plugins/`, or upload the plugin ZIP in Plugins › Add New › Upload Plugin. The folder name must be `woocommerce-oen-payment`.
2. Activate the plugin. WooCommerce must be active.
3. In WooCommerce › Settings › General, set the currency to New Taiwan Dollar (TWD) and the number of decimals to 0.
4. In the OEN back office, find your domain name under 總設定 › Oen 服務資訊 (this is the plugin's MerchantID), and generate a Secret Key under 總設定 › OenPay Embed › Embed API Key. The Secret Key is shown only once.
5. In WooCommerce › Settings › OEN, tick "Enable OEN gateway method" and "OEN sandbox" (for testing), enter the MerchantID and the Secret Key, leave "Webhook Secret" empty, and save. The message "OEN webhook registered (…)" or "OEN webhook updated (…)" confirms the connection. The signing secret is stored automatically.
6. In WooCommerce › Settings › Payments, enable OEN Credit and/or OEN Cvs.
7. Place a test order with each payment method.
8. To go live: untick "OEN sandbox", enter the production MerchantID and Secret Key, tick "Re-register webhook", and save.

Before you use the OEN test environment, ask OEN to confirm that Hosted Checkout is enabled for it.

== Frequently Asked Questions ==

= Do I need to set up the webhook in the OEN back office? =

No. The plugin registers it when you first save the settings. The OEN back office can show "尚未註冊 Webhook" (no webhook registered) under OenPay Embed Webhook 健康狀態. This does not affect payment notifications. Do not add another webhook there.

= After changing environment or site URL, orders are not updated =

Tick "Re-register webhook" and save. When you switch environment, also enter that environment's MerchantID and Secret Key. After a site URL change, the plugin creates a webhook for the new URL; ask OEN to remove the old one if needed.

= Why is a CVS order "On hold"? =

The buyer pays later at the convenience store, so the order waits for OEN's payment notification. WooCommerce's "Hold stock" setting cancels only "Pending payment" orders, so CVS orders are not cancelled by it. A CVS order that already has a payment code stays "On hold" after the hosted page time limit; the buyer can pay until the payment deadline.

= The CVS payment code is not shown =

The plugin looks up the code about 1, 4, 14 and 44 minutes after the order goes on hold. When the buyer returns from the OEN page, the code is usually not there yet; the OEN completion page shows it. If the code never appears, check WooCommerce › Status › Scheduled Actions for `oen_payment_info_sync` items that stay pending, and make sure WP-Cron runs.

= How do I refund? =

On a credit card order, use the refund button "via OEN Credit". Do not use "Refund manually": it only records the refund in WooCommerce and does not return the money. CVS orders cannot be refunded through OEN.

= I refunded in the OEN back office. Does WooCommerce update? =

No. Record the refund in WooCommerce with "Refund manually" and the same amount. Do not also refund "via OEN Credit": OEN may reject it, or it may refund the buyer again. Start refunds from WooCommerce so both records match.

= My Secret Key leaked =

In the OEN back office, open 總設定 › OenPay Embed › Embed API Key, click 撤銷 (revoke) to disable the key, then generate a new key. Enter the new Secret Key in WooCommerce › Settings › OEN, tick "Re-register webhook", and save.

== External services ==

This plugin connects to OEN Payment, operated by Oen Tech (應援科技, https://oen.tw). It is required to take payments.

* What it is used for: to create an OEN payment at checkout, check payment status, cancel a superseded payment, fetch CVS payment codes, create refunds, and register the plugin's webhook.
* When data is sent: when a buyer places an order with an OEN payment method, when OEN sends a payment notification, when the plugin fetches a CVS payment code, when a merchant refunds from the order screen, and when the OEN settings are saved.
* What data is sent: order total (TWD), order number, billing name and email, product details (items, fees, shipping), the store pages to return to after payment, the site's webhook URL, and, when you refund, the refund amount and the reason you enter.
* Endpoints: `https://api.oen.tw` (production) and `https://api.testing.oen.tw` (test).
* OEN terms of service: https://oen.tw/terms
* OEN privacy policy: https://oen.tw/privacy

== Changelog ==

= 1.0.4 =
* Fix: when a buyer goes back and switches to the other OEN payment method, the plugin creates a new OEN payment and cancels the old one. Before, the buyer was sent back to the payment method they had left.
* Fix: switching payment method no longer blocks payment when the order total changes (for example, a different fee per payment method).
* Fix: an OEN refund notification is no longer lost when an admin refund fails at the same time.
* Fix: the CVS payment deadline is shown in the site's time zone and date/time format. Before, the UTC time from OEN was shown, eight hours early for Taiwan.
* Translation: the Traditional Chinese (zh_TW) translation is complete. Before, a zh_TW site still showed English for the payment information, the "Re-register webhook" setting, webhook notices, order notes, checkout and refund errors, and OEN API errors.
* Compatibility: tested up to WordPress 7.1 and WooCommerce 11.1.

= 1.0.3 =
* Fix: the CVS payment code now reaches the order, and is shown to the buyer, the merchant and in emails. It was previously stored only when a payment completed, so an order still awaiting payment never had it, and no screen displayed it.
* The plugin header version now matches the release (earlier releases all said 1.0.0).
* Compatibility: tested up to WooCommerce 11.0.

= 1.0.2 =
* Fix: the OEN settings tab now registers. It never appeared before, so a fresh install had no way to enter a merchant id or secret key, and no OEN payment method could be enabled at all.
* Fix: refunding from the order screen now actually refunds. The gateway had no refund implementation, so the only available button marked the order refunded while the payment was never returned.

= 1.0.1 =
* Event-driven integration with OEN Hosted Checkout: the webhook is registered automatically when the settings are saved, and an OEN refund notification creates the matching WooCommerce refund.
* Security: every payment result is confirmed with OEN, the amount check is mandatory, webhook content is sanitized, and one order cannot be processed twice at the same time.
* CVS orders are set to "On hold" so they are not cancelled automatically.
* Webhooks follow the transaction status returned by OEN; a non-success status is no longer always treated as a failed payment.
* Payment failures no longer show internal error details to the buyer, and failure code messages no longer reflect arbitrary text.
* Product details include tax, fees and rounding so the total matches the order amount.
* WooCommerce Cart & Checkout Blocks support.

= 1.0.0 =
* Initial release
* Credit card payment via OEN hosted checkout
* CVS (convenience store) payment via OEN hosted checkout
* Webhook handler for async payment confirmation
* Payment info in order emails
* zh_TW Traditional Chinese translations
