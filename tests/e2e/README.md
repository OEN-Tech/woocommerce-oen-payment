# L3 — Real dev/qa E2E (Hosted Checkout)

Verifies the plugin against the **real** OEN Hosted Checkout backend once, to
calibrate the L1/L2 stubs against reality. L1 (`tests/run-unit.sh`) and L2
(`tests/runtime/run-webhook-runtime-test.sh`) run with no backend; this is the
only layer that touches the live API.

## Why this is gated
- **Hosted Checkout is live only on `development` and `qa`, not production.**
  `api.oen.tw/api/hosted-checkout/*` 404s today. Use `api.development.oen.tw/api`
  (default) or `api.qa.oen.tw/api`.
- **No self-serve `sk_` issuance.** You need a `sk_test_...` key for a domain whose
  `paymentServiceStatus = ACTIVATE`, handed over out-of-band.
- **The backend cannot reach `localhost`.** The webhook must be registered at a public
  tunnel URL, so `checkout_session.completed` can come back.

## Prereqs
1. `sk_test_...` + `merchant_id` for a Hosted-Checkout-enabled dev/qa domain (ACTIVATE).
2. Bench installed once: in `wordpress-payment-plugin/` run `docker compose up -d && ./setup.sh`.
3. `docker-compose.override.yml` present in the bench (mount → worktree, sets `OEN_API_BASE_URL`). Restart the bench after adding it: `docker compose down && docker compose up -d`.
4. A tunnel: `cloudflared tunnel --url http://localhost:8080` (note the printed `https://…` URL).

## Run
```sh
# from this worktree:
OEN_MERCHANT_ID=<merchant> \
OEN_SK=sk_test_xxx \
TUNNEL_URL=https://<sub>.trycloudflare.com \
OEN_API_BASE_URL=https://api.development.oen.tw/api \
  sh tests/e2e/configure-dev-bench.sh
```
Then place an order at http://localhost:8080 with **OEN Credit** or **OEN CVS**,
complete payment on the real hosted checkout page, and let the webhook come back.

## Evidence to capture (per the "keep evidence for all tests" rule)
Store under `tests/evidence/<YYYYMMDD-HHMM>-t11-L3-dev/`:
- **Screenshots**: classic checkout + **block checkout** (proves the Blocks port shows
  OEN as selectable), the redirect to the real hosted checkout page, WP admin order screen.
- **Recording**: the checkout → pay → return flow (screen recording or Playwright video).
- **Payloads**: the real `checkout_session.completed` body + `OenPay-Signature` header
  (from `wp-content/debug.log`, source `oen-payment*`), and the create/GET session responses.
- **Order state**: `wp eval-file .../tests/e2e/inspect-order-by-id.php <order_id>` before/after.

## What L3 confirms that stubs cannot
Real signature passes against a real `whsec_`; the real GET-session shape (top-level
status, top-level `paymentInfo` for CVS) matches what the plugin reads; the real
`amount == Σ productDetails` reconciliation is accepted (no `V0001`); the Blocks
methods actually render + redirect in a real WooCommerce block checkout.
