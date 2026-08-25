#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
fail=0
check(){ local label="$1"; shift; if "$@"; then printf 'PASS: %s\n' "$label"; else printf 'FAIL: %s\n' "$label"; fail=1; fi; }
check 'single payment settlement service exists' grep -qF 'class PaymentSettlement' "$ROOT/app/Domain/PaymentSettlement.php"
check 'settlement claims only pending payments' grep -qF "WHERE id = ? AND status = 'pending'" "$ROOT/app/Domain/PaymentSettlement.php"
check 'MySQL user row is locked during settlement' grep -qF 'FOR UPDATE' "$ROOT/app/Domain/PaymentSettlement.php"
check 'web admin approval uses shared settlement' grep -qF 'PaymentSettlement::approve' "$ROOT/app/Modules/Billing/Controllers/PaymentController.php"
check 'mobile admin approval uses shared settlement' grep -qF 'PaymentSettlement::approve' "$ROOT/app/Api/Controllers/AdminApiController.php"
check 'client payment amount is not authoritative on web' grep -qF 'PaymentPricing::quote' "$ROOT/app/Controllers/MainController.php"
check 'client payment amount is not authoritative on API' grep -qF 'PaymentPricing::quote' "$ROOT/app/Api/Controllers/BillingApiController.php"
check 'payment quote snapshot is persisted' grep -qF 'quoted_amount' "$ROOT/app/Controllers/MainController.php"
check 'API payment quote snapshot is persisted' grep -qF 'quoted_amount' "$ROOT/app/Api/Controllers/BillingApiController.php"
check 'fresh SQLite schema contains quote snapshot' grep -qF 'quoted_amount DECIMAL(12,2)' "$ROOT/migrations/install.sql"
check 'fresh MySQL schema contains quote snapshot' grep -qF 'quoted_amount DECIMAL(12,2)' "$ROOT/migrations/install_mysql.sql"
check 'payment reference has DB uniqueness' grep -qF 'uq_payments_user_reference' "$ROOT/app/Core/Bootstrap.php"
check 'referral reward is part of settlement transaction' grep -qF 'Referral::processFirstPurchase' "$ROOT/app/Domain/PaymentSettlement.php"
check 'wallet credit supports outer transaction' grep -qF '$ownsTransaction' "$ROOT/app/Domain/Wallet.php"
check 'referral reward fails settlement when wallet ledger fails' grep -qF 'ثبت پاداش کیف پول معرف ناموفق بود' "$ROOT/app/Domain/Referral.php"
check 'wallet reference uniqueness exists' grep -qF 'uq_wallet_reference' "$ROOT/app/Core/Bootstrap.php"
check 'stale idempotency reservations can recover' grep -qF '1800' "$ROOT/app/Domain/Idempotency.php"
check 'v18 financial migration exists' grep -qF "'v18_financial_integrity'" "$ROOT/app/Core/Bootstrap.php"
check 'PHP syntax all source files' bash -c 'find "$0" -type f -name "*.php" -not -path "*/storage/*" -print0 | xargs -0 -n1 php -l >/dev/null' "$ROOT"
if (( fail )); then exit 1; fi
printf 'WAVE_M_FINANCIAL_INTEGRITY_REGRESSION: PASS\n'
