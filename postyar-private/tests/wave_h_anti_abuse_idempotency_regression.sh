#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
fail(){ echo "FAIL: $1"; exit 1; }
pass(){ echo "PASS: $1"; }

grep -q "class AntiAbuse" "$ROOT/app/Domain/AntiAbuse.php" || fail "AntiAbuse missing"
grep -q "UNIQUE(claim_type, identity_hash)" "$ROOT/migrations/install.sql" || fail "SQLite claim uniqueness missing"
grep -q "UNIQUE KEY uq_anti_abuse_claim" "$ROOT/migrations/install_mysql.sql" || fail "MySQL claim uniqueness missing"
grep -q "v14_anti_abuse_idempotency" "$ROOT/app/Core/Bootstrap.php" || fail "v14 migration missing"
grep -q "class Idempotency" "$ROOT/app/Domain/Idempotency.php" || fail "Idempotency missing"
grep -q "UNIQUE(user_id, operation, idem_key)" "$ROOT/migrations/install.sql" || fail "SQLite idempotency uniqueness missing"
grep -q "UNIQUE KEY uq_idempotency" "$ROOT/migrations/install_mysql.sql" || fail "MySQL idempotency uniqueness missing"
grep -q "idempotency_key.*required" "$ROOT/app/Api/Controllers/BillingApiController.php" || fail "Payment API idempotency not required"
grep -q "Idempotency::reserve" "$ROOT/app/Api/Controllers/BillingApiController.php" || fail "Payment API reserve missing"
grep -q "Idempotency::reserve" "$ROOT/app/Api/Controllers/WalletReferralApiController.php" || fail "Wallet API reserve missing"
grep -q "AntiAbuse::claimChannel" "$ROOT/app/Domain/ChannelManager.php" || fail "Channel claim missing"
grep -q "AntiAbuse::claimFreeTrial" "$ROOT/app/Core/Auth.php" || fail "Free trial claim missing"
grep -q "name=\"phone\"" "$ROOT/app/Views/home.php" || fail "Web registration phone field missing"
grep -q "'phone'           => 'required'" "$ROOT/app/Api/Controllers/AuthApiController.php" || fail "API registration phone requirement missing"
grep -q "FOR UPDATE" "$ROOT/app/Domain/PaymentSettlement.php" || fail "Payment approval user lock missing"
grep -q "FOR UPDATE" "$ROOT/app/Domain/PaymentSettlement.php" || fail "Billing user lock missing"
grep -q "FOR UPDATE" "$ROOT/app/Modules/Users/Controllers/UserController.php" || fail "Manual grant user lock missing"
php -r 'require "app/Domain/AntiAbuse.php"; $r=["۰۹۱۲۳۴۵۶۷۸۹"=>"09123456789","+989123456789"=>"09123456789","00989123456789"=>"09123456789","989123456789"=>"09123456789"]; foreach($r as $in=>$out){if(\WHCM\Domain\AntiAbuse::normalizePhone($in)!==$out) exit(2);} if(!\WHCM\Domain\AntiAbuse::validPhone("09123456789")) exit(3); if(\WHCM\Domain\AntiAbuse::validPhone("02112345678")) exit(4);' || fail "Phone normalization/validation"
php -r 'require "app/Domain/Idempotency.php"; if(\WHCM\Domain\Idempotency::normalizeKey("bad key")!==null) exit(2); if(\WHCM\Domain\Idempotency::normalizeKey("android-ABC_01:xyz")===null) exit(3);' || fail "Idempotency key validation"
pass "Wave H anti-abuse/idempotency static regression"
