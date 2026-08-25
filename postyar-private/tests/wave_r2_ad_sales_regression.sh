#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
pass(){ echo "PASS: $1"; }
fail(){ echo "FAIL: $1" >&2; exit 1; }

for f in migrations/v24_ad_sales_workflow.sql migrations/v24_ad_sales_workflow_mysql.sql app/Domain/AdSales.php app/Payments/PaymentGatewayInterface.php app/Payments/README.md docs/WAVE-R2-AD-SALES-WORKFLOW.md; do
  test -s "$ROOT/$f" || fail "missing $f"
done
pass "R2 migration/domain/payment-boundary artifacts exist"

grep -q "awaiting_payment" "$ROOT/app/Domain/AdSales.php" || fail "awaiting_payment state missing"
grep -q "pending_verification" "$ROOT/app/Domain/AdSales.php" || fail "payment verification state missing"
grep -q "status='paid'" "$ROOT/app/Domain/AdSales.php" || fail "paid transition missing"
grep -q "payment_status='paid'" "$ROOT/app/Domain/Advertising.php" || fail "public advertising payment gate missing"
pass "payment-gated activation invariant present"

grep -q "quoted_amount" "$ROOT/app/Domain/AdSales.php" || fail "server quote missing"
grep -q "owner_user_id=?" "$ROOT/app/Domain/AdSales.php" || fail "owner isolation missing"
grep -q "max_concurrent" "$ROOT/app/Domain/AdSales.php" || fail "placement capacity guard missing"
grep -q "uq_ad_orders_owner_payment_reference" "$ROOT/migrations/v24_ad_sales_workflow.sql" || fail "payment reference uniqueness missing"
pass "server-authoritative quote, owner isolation and capacity guard present"

grep -q "PaymentGatewayInterface" "$ROOT/app/Payments/README.md" || fail "future gateway boundary missing"
grep -q "verifyCallback" "$ROOT/app/Payments/PaymentGatewayInterface.php" || fail "gateway verification contract missing"
pass "future gateway verification boundary present"

for f in app/Domain/AdSales.php app/Domain/Advertising.php app/Controllers/MainController.php app/Api/Controllers/AdvertisingApiController.php app/Core/Bootstrap.php; do php -l "$ROOT/$f" >/dev/null || fail "PHP syntax: $f"; done
pass "R2 PHP syntax"

echo "WAVE_R2_AD_SALES_REGRESSION: PASS"
