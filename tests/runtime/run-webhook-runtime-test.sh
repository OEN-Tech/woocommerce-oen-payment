#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
RUNTIME_DIR="$ROOT_DIR/tests/runtime"
COMPOSE_FILE="$RUNTIME_DIR/docker-compose.runtime.yml"
PROJECT_NAME="${PROJECT_NAME:-oen-webhook-runtime}"
WP_RUNTIME_PORT="${WP_RUNTIME_PORT:-18080}"
WP_ADMIN_USER="${WP_ADMIN_USER:-admin}"
WP_ADMIN_PASSWORD="${WP_ADMIN_PASSWORD:-password}"
WP_ADMIN_EMAIL="${WP_ADMIN_EMAIL:-admin@example.com}"
WEBHOOK_SECRET="whsec_runtime_secret"

compose() {
  docker compose -f "$COMPOSE_FILE" -p "$PROJECT_NAME" "$@"
}

cleanup() {
  if [[ "${KEEP_RUNTIME_STACK:-0}" != "1" ]]; then
    compose down -v --remove-orphans >/dev/null 2>&1 || true
  fi
}

trap cleanup EXIT

wait_for_http() {
  local url="$1"
  local retries="${2:-60}"
  local attempt=0

  until curl -fsS "$url" >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [[ "$attempt" -ge "$retries" ]]; then
      echo "Timed out waiting for $url" >&2
      return 1
    fi
    sleep 2
  done
}

wpcli() {
  compose run --rm wpcli wp --allow-root --path=/var/www/html --skip-plugins=woocommerce-oen-payment "$@"
}

prepare_wordpress_filesystem() {
  compose exec -T wordpress sh -lc '
    mkdir -p /var/www/html/wp-content/upgrade /var/www/html/wp-content/uploads /var/www/html/wp-content/plugins
    chown -R www-data:www-data /var/www/html/wp-content
  '
}

bootstrap_wordpress() {
  compose up -d db wordpress api-stub

  wait_for_http "http://127.0.0.1:${WP_RUNTIME_PORT}/"
  wait_for_http "http://127.0.0.1:${WP_RUNTIME_PORT}/wp-login.php"
  wait_for_http "http://127.0.0.1:${WP_RUNTIME_PORT}/health" 1 || true
  wait_for_http "http://127.0.0.1:${WP_RUNTIME_PORT}/" 5
  wait_for_http "http://127.0.0.1:${WP_RUNTIME_PORT}/" 5
  wait_for_http "http://127.0.0.1:${WP_RUNTIME_PORT}/" 5
  prepare_wordpress_filesystem

  if ! wpcli core is-installed >/dev/null 2>&1; then
    wpcli core install \
      --url="http://127.0.0.1:${WP_RUNTIME_PORT}" \
      --title="OEN Runtime" \
      --admin_user="$WP_ADMIN_USER" \
      --admin_password="$WP_ADMIN_PASSWORD" \
      --admin_email="$WP_ADMIN_EMAIL"
  fi

  if ! wpcli plugin is-installed woocommerce >/dev/null 2>&1; then
    wpcli plugin install woocommerce --activate
  else
    wpcli plugin activate woocommerce >/dev/null 2>&1 || true
  fi

  wpcli plugin activate woocommerce-oen-payment >/dev/null 2>&1 || true

  wpcli option update oen_enabled yes
  wpcli option update oen_merchant_id runtime-merchant
  wpcli option update oen_api_token sk_test_runtime_secret
  wpcli option update oen_sandbox yes
  wpcli option update oen_webhook_secret "$WEBHOOK_SECRET"
}

json_field() {
  local file="$1"
  local path="$2"
  php -r '
    $data = json_decode(file_get_contents($argv[1]), true);
    $path = explode(".", $argv[2]);
    $cursor = $data;
    foreach ($path as $segment) {
      if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
        exit(1);
      }
      $cursor = $cursor[$segment];
    }
    if (is_bool($cursor)) {
      echo $cursor ? "true" : "false";
      exit(0);
    }
    if (is_scalar($cursor)) {
      echo (string) $cursor;
      exit(0);
    }
    echo json_encode($cursor);
  ' "$file" "$path"
}

assert_equals() {
  local expected="$1"
  local actual="$2"
  local message="$3"

  if [[ "$expected" != "$actual" ]]; then
    echo "Assertion failed: $message" >&2
    echo "  expected: $expected" >&2
    echo "  actual:   $actual" >&2
    exit 1
  fi
}

prepare_order() {
  local case_name="$1"
  wpcli eval-file "/var/www/html/wp-content/plugins/woocommerce-oen-payment/tests/runtime/prepare-order.php" "$case_name"
}

inspect_order() {
  local case_name="$1"
  wpcli eval-file "/var/www/html/wp-content/plugins/woocommerce-oen-payment/tests/runtime/inspect-order.php" "$case_name"
}

build_signature() {
  local payload="$1"
  local secret="$2"
  local timestamp
  timestamp="$(date +%s)"
  php -r '
    $timestamp = $argv[1];
    $payload = $argv[2];
    $secret = $argv[3];
    echo "t={$timestamp},v1=" . hash_hmac("sha256", $timestamp . "." . $payload, $secret);
  ' "$timestamp" "$payload" "$secret"
}

post_webhook() {
  local payload="$1"
  local signature_header="$2"
  local response_file="$3"

  curl -sS -o "$response_file" -w "%{http_code}" \
    -X POST "http://127.0.0.1:${WP_RUNTIME_PORT}/?wc-api=oen_payment" \
    -H "Content-Type: application/json" \
    -H "OenPay-Signature: ${signature_header}" \
    --data "$payload"
}

run_signed_ambiguous_case() {
  local prep_json
  local response_file
  local order_file
  local status_code
  local payload
  local signature

  prep_json="$(prepare_order 'signed-ambiguous')"
  payload="$(php -r '
    $data = json_decode($argv[1], true);
    echo json_encode([
      "type" => "checkout_session.completed",
      "data" => [
        "id" => $data["session_id"],
        "orderId" => $data["oen_order_id"],
        "status" => "completed",
      ],
    ]);
  ' "$prep_json")"
  signature="$(build_signature "$payload" "$WEBHOOK_SECRET")"
  response_file="$(mktemp)"
  order_file="$(mktemp)"

  status_code="$(post_webhook "$payload" "$signature" "$response_file")"
  inspect_order 'signed-ambiguous' >"$order_file"

  assert_equals "200" "$status_code" "signed ambiguous webhook should return 200"
  assert_equals "Event ignored" "$(json_field "$response_file" "message")" "signed ambiguous webhook should be ignored"
  assert_equals "false" "$(json_field "$order_file" "is_paid")" "signed ambiguous webhook must not mark order paid"
  assert_equals "" "$(json_field "$order_file" "oen_paid_at" || true)" "signed ambiguous webhook must not set paid_at"

  rm -f "$response_file" "$order_file"
}

run_signed_success_case() {
  local prep_json
  local response_file
  local order_file
  local status_code
  local payload
  local signature

  prep_json="$(prepare_order 'signed-success')"
  payload="$(php -r '
    $data = json_decode($argv[1], true);
    echo json_encode([
      "type" => "checkout_session.completed",
      "data" => [
        "id" => $data["session_id"],
        "orderId" => $data["oen_order_id"],
        "status" => "completed",
      ],
    ]);
  ' "$prep_json")"
  signature="$(build_signature "$payload" "$WEBHOOK_SECRET")"
  response_file="$(mktemp)"
  order_file="$(mktemp)"
  replay_file="$(mktemp)"

  status_code="$(post_webhook "$payload" "$signature" "$response_file")"
  inspect_order 'signed-success' >"$order_file"

  assert_equals "200" "$status_code" "signed success webhook should return 200"
  assert_equals "ok" "$(json_field "$response_file" "status")" "signed success webhook should return ok payload"
  assert_equals "true" "$(json_field "$order_file" "is_paid")" "signed success webhook should mark order paid"
  local order_status
  order_status="$(json_field "$order_file" "status")"
  if [[ "$order_status" != "processing" && "$order_status" != "completed" ]]; then
    echo "Assertion failed: signed success webhook should transition order status" >&2
    echo "  expected: processing or completed" >&2
    echo "  actual:   $order_status" >&2
    exit 1
  fi
  assert_equals "txn_runtime_success_001" "$(json_field "$order_file" "oen_transaction_hid")" "signed success webhook should persist transaction HID"
  assert_equals "txn_runtime_success_internal" "$(json_field "$order_file" "oen_transaction_id")" "signed success webhook should persist transaction ID"
  if [[ -z "$(json_field "$order_file" "oen_paid_at")" ]]; then
    echo "Assertion failed: signed success webhook should set paid_at" >&2
    exit 1
  fi

  status_code="$(post_webhook "$payload" "$signature" "$replay_file")"
  assert_equals "200" "$status_code" "duplicate signed success webhook should still return 200"
  assert_equals "Already processed" "$(json_field "$replay_file" "message")" "duplicate signed success webhook should report already processed"

  rm -f "$response_file" "$order_file" "$replay_file"
}

run_signed_stale_case() {
  local prep_json
  local response_file
  local order_file
  local status_code
  local payload
  local signature

  prep_json="$(prepare_order 'signed-stale')"
  payload="$(php -r '
    $data = json_decode($argv[1], true);
    echo json_encode([
      "type" => "checkout_session.completed",
      "data" => [
        "id" => "sess_runtime_stale",
        "orderId" => $data["oen_order_id"],
        "status" => "completed",
      ],
    ]);
  ' "$prep_json")"
  signature="$(build_signature "$payload" "$WEBHOOK_SECRET")"
  response_file="$(mktemp)"
  order_file="$(mktemp)"

  status_code="$(post_webhook "$payload" "$signature" "$response_file")"
  inspect_order 'signed-stale' >"$order_file"

  assert_equals "200" "$status_code" "signed stale webhook should return 200"
  assert_equals "Stale event ignored" "$(json_field "$response_file" "message")" "signed stale webhook should be ignored"
  assert_equals "false" "$(json_field "$order_file" "is_paid")" "signed stale webhook must not mark order paid"

  rm -f "$response_file" "$order_file"
}

run_signed_missing_amount_case() {
  local prep_json
  local response_file
  local order_file
  local status_code
  local payload
  local signature

  prep_json="$(prepare_order 'signed-missing-amount')"
  payload="$(php -r '
    $data = json_decode($argv[1], true);
    echo json_encode([
      "type" => "checkout_session.completed",
      "data" => [
        "id" => $data["session_id"],
        "orderId" => $data["oen_order_id"],
        "status" => "completed",
      ],
    ]);
  ' "$prep_json")"
  signature="$(build_signature "$payload" "$WEBHOOK_SECRET")"
  response_file="$(mktemp)"
  order_file="$(mktemp)"

  status_code="$(post_webhook "$payload" "$signature" "$response_file")"
  inspect_order 'signed-missing-amount' >"$order_file"

  assert_equals "502" "$status_code" "signed missing-amount webhook should fail closed with 502"
  assert_equals "Verification failed" "$(json_field "$response_file" "message")" "signed missing-amount webhook should surface verification failure"
  assert_equals "false" "$(json_field "$order_file" "is_paid")" "signed missing-amount webhook must not mark order paid"

  rm -f "$response_file" "$order_file"
}

run_invalid_signature_case() {
  local prep_json
  local response_file
  local order_file
  local status_code
  local payload

  prep_json="$(prepare_order 'invalid-signature')"
  payload="$(php -r '
    $data = json_decode($argv[1], true);
    echo json_encode([
      "type" => "checkout_session.completed",
      "data" => [
        "id" => $data["session_id"],
        "orderId" => $data["oen_order_id"],
        "status" => "completed",
      ],
    ]);
  ' "$prep_json")"
  response_file="$(mktemp)"
  order_file="$(mktemp)"

  status_code="$(post_webhook "$payload" "t=$(date +%s),v1=invalidsignature" "$response_file")"
  inspect_order 'invalid-signature' >"$order_file"

  assert_equals "403" "$status_code" "invalid signature webhook should be rejected"
  assert_equals "Invalid webhook signature" "$(json_field "$response_file" "message")" "invalid signature webhook should surface parser rejection"
  assert_equals "false" "$(json_field "$order_file" "is_paid")" "invalid signature webhook must not mark order paid"

  rm -f "$response_file" "$order_file"
}

run_cvs_pending_case() {
  local prep_json
  local response_file
  local order_file
  local status_code
  local payload
  local signature

  prep_json="$(prepare_order 'cvs-pending')"
  payload="$(php -r '
    $data = json_decode($argv[1], true);
    echo json_encode([
      "type" => "checkout_session.completed",
      "data" => [
        "id" => $data["session_id"],
        "orderId" => $data["oen_order_id"],
        "status" => "completed",
      ],
    ]);
  ' "$prep_json")"
  signature="$(build_signature "$payload" "$WEBHOOK_SECRET")"
  response_file="$(mktemp)"
  order_file="$(mktemp)"

  status_code="$(post_webhook "$payload" "$signature" "$response_file")"
  inspect_order 'cvs-pending' >"$order_file"

  assert_equals "200" "$status_code" "cvs pending webhook should return 200"
  assert_equals "Event ignored" "$(json_field "$response_file" "message")" "cvs pending webhook should be ignored"
  assert_equals "false" "$(json_field "$order_file" "is_paid")" "cvs pending webhook must not mark order paid"
  assert_equals "pending" "$(json_field "$order_file" "status")" "cvs pending webhook must leave order in pending status"
  assert_equals "" "$(json_field "$order_file" "oen_paid_at" || true)" "cvs pending webhook must not set paid_at"
  assert_equals "" "$(json_field "$order_file" "oen_cvs_code" || true)" "cvs pending webhook must not store CVS code"

  rm -f "$response_file" "$order_file"
}

bootstrap_wordpress
run_cvs_pending_case
run_signed_ambiguous_case
run_signed_success_case
run_signed_stale_case
run_signed_missing_amount_case
run_invalid_signature_case

echo "WordPress runtime webhook harness passed."
