#!/usr/bin/env sh
set -eu
# L3 E2E: configure the WordPress bench against the REAL dev/qa Hosted Checkout API,
# and register a webhook at the PUBLIC tunnel URL (the backend cannot reach localhost).
#
# Prereqs:
#   - Bench installed once:  (in wordpress-payment-plugin)  docker compose up -d && ./setup.sh
#   - docker-compose.override.yml present (points the plugin mount at the worktree and
#     sets OEN_API_BASE_URL). See tests/e2e/README.md.
#   - A tunnel to the bench:  cloudflared tunnel --url http://localhost:8080
#
# Required env:
#   OEN_MERCHANT_ID  merchant id of a Hosted-Checkout-enabled domain
#   OEN_SK           sk_test_...  (the domain must be paymentServiceStatus=ACTIVATE)
#   TUNNEL_URL       https URL printed by cloudflared (e.g. https://foo.trycloudflare.com)
# Optional:
#   OEN_API_BASE_URL default https://api.development.oen.tw/api  (or https://api.qa.oen.tw/api)
#   BENCH_DIR        default /Users/rex1/work/Projects/wordpress-payment-plugin

: "${OEN_MERCHANT_ID:?set OEN_MERCHANT_ID}"
: "${OEN_SK:?set OEN_SK (sk_test_...)}"
: "${TUNNEL_URL:?set TUNNEL_URL (cloudflared public URL)}"
OEN_API_BASE_URL="${OEN_API_BASE_URL:-https://api.development.oen.tw/api}"
BENCH_DIR="${BENCH_DIR:-/Users/rex1/work/Projects/wordpress-payment-plugin}"
export OEN_API_BASE_URL

WEBHOOK_URL="${TUNNEL_URL%/}/?wc-api=oen_payment"

cd "$BENCH_DIR"
wpcli() { docker compose run --rm wpcli wp --allow-root "$@"; }

echo "== bring bench up (mount=worktree, OEN_API_BASE_URL=$OEN_API_BASE_URL) =="
docker compose up -d db wordpress >/dev/null
wpcli plugin activate woocommerce woocommerce-oen-payment >/dev/null 2>&1 || true

echo "== set OEN options =="
wpcli option update oen_enabled yes >/dev/null
wpcli option update oen_merchant_id "$OEN_MERCHANT_ID" >/dev/null
wpcli option update oen_api_token "$OEN_SK" >/dev/null
wpcli option update oen_sandbox yes >/dev/null

echo "== register webhook -> $WEBHOOK_URL =="
wpcli eval-file "wp-content/plugins/woocommerce-oen-payment/tests/e2e/register-webhook.php" "$WEBHOOK_URL"

cat <<EOF

Configured. Next (capture evidence at each step):
  1. Open http://localhost:8080 and place an order using "OEN Credit" or "OEN CVS".
     - Screenshot: checkout page (classic AND block checkout), the redirect to the
       real hosted checkout page (proves a real session was created on $OEN_API_BASE_URL).
  2. Complete payment on the hosted checkout page (test card / CVS code).
  3. The backend delivers checkout_session.completed -> $WEBHOOK_URL -> the order
     should transition to processing/completed.
  4. Verify + capture:
       docker compose run --rm wpcli wp --allow-root eval-file \\
         wp-content/plugins/woocommerce-oen-payment/tests/e2e/inspect-order-by-id.php <order_id>
     - Screenshot: WP admin order screen; grab wp-content/debug.log webhook lines.
EOF
