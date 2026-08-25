#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
pass(){ echo "PASS: $1"; }
fail(){ echo "FAIL: $1" >&2; exit 1; }

php -l "$ROOT/app/Core/RateLimit.php" >/dev/null || fail "RateLimit syntax"
php -l "$ROOT/app/Domain/VerificationCode.php" >/dev/null || fail "VerificationCode syntax"
php -l "$ROOT/app/Api/Controllers/AuthApiController.php" >/dev/null || fail "Auth API syntax"
php -l "$ROOT/app/Controllers/MainController.php" >/dev/null || fail "MainController syntax"
pass "auth/OTP PHP syntax"

grep -q "consume('api_phone_login_request'" "$ROOT/app/Api/Controllers/AuthApiController.php" || fail "API phone-login request rate limit"
grep -q "consume('api_phone_login_verify'" "$ROOT/app/Api/Controllers/AuthApiController.php" || fail "API phone-login verify rate limit"
grep -q "findActive((int)\$user\['id'\], 'phone_login', \$code)" "$ROOT/app/Api/Controllers/AuthApiController.php" || fail "OTP scoped to phone owner"
grep -q "VerificationCode::consume((int)\$record\['id'])" "$ROOT/app/Api/Controllers/AuthApiController.php" || fail "OTP atomic consumption"
pass "API OTP anti-bruteforce and ownership"

grep -q "handlePhoneLoginRequest" "$ROOT/public/index.php" || fail "Web phone login request route"
grep -q "handlePhoneLoginVerify" "$ROOT/public/index.php" || fail "Web phone login verify route"
grep -q "loginWithPhoneBinding" "$ROOT/app/Controllers/MainController.php" || fail "Web password login phone binding"
pass "Web phone-login contract"

grep -q "UPDATE verification_codes SET used = 1 WHERE id = ? AND used = 0" "$ROOT/app/Domain/VerificationCode.php" || fail "atomic OTP consume"
grep -q "uq_rate_limits_ip_action" "$ROOT/app/Core/Bootstrap.php" || fail "rate-limit uniqueness migration"
grep -q "uq_users_phone" "$ROOT/app/Core/Bootstrap.php" || fail "phone uniqueness migration"
pass "database uniqueness and atomicity guards"

grep -q "revokeAllUserTokens" "$ROOT/app/Controllers/MainController.php" || fail "web password-change token revocation"
grep -q "revokeAllUserTokens" "$ROOT/app/Api/Controllers/AuthApiController.php" || fail "API reset token revocation"
pass "password-change session/token invalidation"

grep -q "'token_hash' => hash('sha256', \$token)" "$ROOT/app/Api/Controllers/AuthApiController.php" || fail "API reset token hashing"
pass "reset token at-rest hashing"

echo "WAVE_I_AUTH_OTP_REGRESSION: PASS"
