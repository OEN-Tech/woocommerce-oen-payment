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
* Verifies and reuses an active Hosted Checkout session inside an order-level advisory lock for repeated unpaid checkout attempts

== Installation ==

1. Upload the `woocommerce-oen-payment` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu in WordPress
3. Go to WooCommerce > Settings > OEN to configure your MerchantID, Secret Key, and Webhook Secret
4. Go to WooCommerce > Settings > Payments to enable OEN Credit and/or OEN Cvs
5. Configure your webhook URL in OEN CRM backend: `https://yoursite.com/?wc-api=oen_payment`

Hosted Checkout Session API:

* `POST /hosted-checkout/v1/sessions` creates a checkout session and returns a non-empty `id` plus `checkoutUrl`
* `GET /hosted-checkout/v1/sessions/{sessionId}` fetches a single session
* Use `Authorization: Bearer <Secret Key>` for Hosted Checkout Session API calls
* The v1 secret-key contract does not require `merchantId`; the plugin sends `successUrl`, `failureUrl`, and `cancelUrl` instead
* If the create response omits the session `id`, checkout fails instead of storing an empty `_oen_session_id`
* If the same unpaid order re-enters checkout, the plugin first verifies the stored `_oen_session_id` inside an order-level advisory lock with `GET /hosted-checkout/v1/sessions/{sessionId}` and reuses the saved `_oen_checkout_url` while that session is still in flight
* If stored-session verification fails, checkout fails closed instead of silently creating a new live session
* A fresh session is created only after the previous one is in a terminal state such as `completed`, `charged`, `failed`, `expired`, or `cancelled`

Webhook contract:

* Webhooks send `OenPay-Signature: t=...,v1=...`
* The signature is an HMAC-SHA256 over `{timestamp}.{raw_body}` using the configured Webhook Secret
* The `t` timestamp must be within the default 300-second tolerance window
* Webhook payloads arrive as an event envelope with the event `type` plus business payload nested under `data`
* Hosted Checkout session ids are read from `data.id` with `data.sessionId` kept as a backward-compatible fallback
* The current Hosted Checkout event types are `checkout_session.completed`, `checkout_session.failed`, `checkout_session.expired`, `checkout_session.cancelled`, `refund.created`, and `refund.succeeded`
* `refund.succeeded` events are mirrored into a WooCommerce refund: the order is found by `data.sessionId` (matching the stored `_oen_session_id`), a refund is created for `data.amount` in the order currency, and the OEN refund id is recorded so paired `refund.created`/`refund.succeeded` events and retries cannot create duplicate refunds (`refund.created` is acknowledged without acting)
* Refund events require a configured Webhook Secret and are rejected when none is set — unlike checkout-session events they have no server-side re-verification fallback, so their authenticity rests entirely on the signature
* Note: only synchronous refunds (credit card, Line Pay) are mirrored today; CVS refunds and any future asynchronous (`refunding`) refunds are not yet exposed by the Hosted Checkout webhook contract
* The plugin registers its webhook automatically on first save (and when "Re-register webhook" is ticked): it POSTs the site endpoint and event subscriptions to `POST /hosted-checkout/v1/webhooks` and stores the returned id and signing secret, so the Webhook Secret no longer has to be copied by hand
* When a session id is present, the webhook handler prefers `GET /hosted-checkout/v1/sessions/{sessionId}` verification and normalizes the verified session status through one helper path that prefers the top-level `status` and falls back to nested `transaction.status` for forward compatibility
* `transactionHid` verification remains a fallback only when the webhook does not contain a session id
* Stale-attempt protection is primarily bound to `_oen_session_id`, so a matching current session id is accepted even if an older stored `_oen_transaction_hid` differs
* After verified success, the plugin writes authoritative `transactionHid` and `transactionId` values back to order meta
* If the order stores `_oen_session_id`, any webhook missing that session id or carrying a different one is treated as stale and safely ignored
* If the order does not store `_oen_session_id`, any session-only webhook without an authoritative matching `transactionHid` is treated as unverifiable stale risk and safely ignored

Example webhook envelope:

`{"id":"evt_test_123","type":"checkout_session.completed","data":{"id":"sess_123","orderId":"wc_1001","transactionId":"txn_123","transactionHid":"txn_hid_123","status":"completed","paymentMethod":"card","paymentProvider":"oenpay"}}`

== Changelog ==

= 1.0.0 =
* Initial release
* Credit card payment via OEN hosted checkout
* CVS (convenience store) payment via OEN hosted checkout
* Webhook handler for async payment confirmation
* Payment info in order emails
* zh_TW Traditional Chinese translations
