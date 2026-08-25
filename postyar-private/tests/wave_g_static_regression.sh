#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
fail=0
check(){ local label="$1"; shift; if "$@"; then printf 'PASS: %s\n' "$label"; else printf 'FAIL: %s\n' "$label"; fail=1; fi; }
check 'wallet debit is conditional on balance' grep -qF 'WHERE id = ? AND wallet_balance >= ?' "$ROOT/app/Domain/Wallet.php"
check 'points conversion is conditional on available points' grep -qF 'WHERE id = ? AND referral_points >= ?' "$ROOT/app/Domain/Wallet.php"
check 'referral reward claim is conditional on pending state' grep -qF "WHERE id = ? AND status = 'pending'" "$ROOT/app/Domain/Referral.php"
check 'Payment settlement is conditional on pending state' grep -qF "WHERE id = ? AND status = 'pending'" "$ROOT/app/Domain/PaymentSettlement.php"
check 'Web payment approval delegates to guarded module' grep -qF 'new \WHCM\Modules\Billing\Controllers\PaymentController' "$ROOT/app/Controllers/MainController.php"
check 'Module payment approval delegates to settlement' grep -qF 'PaymentSettlement::approve' "$ROOT/app/Modules/Billing/Controllers/PaymentController.php"
check 'concurrency indexes migration exists' grep -qF "'v13_concurrency_indexes'" "$ROOT/app/Core/Bootstrap.php"
if (( fail )); then exit 1; fi
printf 'WAVE_G_STATIC_REGRESSION: PASS\n'
