#!/usr/bin/env sh
# Run all L1 unit/contract harnesses (no Docker, no live backend).
# Exits non-zero if any harness fails. POSIX sh for macOS/Linux/CI parity.
set -u

DIR="$(cd "$(dirname "$0")" && pwd)"
FAIL=0

for t in \
    ApiClientTest \
    WebhookParserTest \
    WebhookHandlerIntegrationTest \
    WebhookRegistrationTest \
    CheckoutParamsTest \
    BlocksPaymentMethodTest
do
    printf '\n===== %s =====\n' "$t"
    if php "$DIR/$t.php"; then
        :
    else
        printf '!!!!! %s FAILED !!!!!\n' "$t"
        FAIL=1
    fi
done

printf '\n'
if [ "$FAIL" -eq 0 ]; then
    printf 'ALL UNIT HARNESSES PASSED\n'
else
    printf 'ONE OR MORE UNIT HARNESSES FAILED\n'
fi
exit "$FAIL"
