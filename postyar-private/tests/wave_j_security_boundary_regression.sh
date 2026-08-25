#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
fail(){ echo "FAIL: $1" >&2; exit 1; }
pass(){ echo "PASS: $1"; }

# State-changing admin actions must never be routable over GET.
for route in suspend-user activate-user delete-user approve-payment delete-plan; do
  if grep -q "Router::get('/hnnh/$route'" "$ROOT/public/index.php"; then
    fail "destructive admin GET route remains: $route"
  fi
done
pass "destructive admin GET routes removed"

# The canonical POST handlers remain protected by CSRF.
for method in suspend activate delete; do
  grep -q "public function $method" "$ROOT/app/Modules/Users/Controllers/UserController.php" || fail "user $method handler missing"
done
grep -q "Csrf::validate(\$_POST\['csrf_token'\]" "$ROOT/app/Modules/Users/Controllers/UserController.php" || fail "user admin handlers missing CSRF"
grep -q "Csrf::validate(\$_POST\['csrf_token'\]" "$ROOT/app/Modules/Billing/Controllers/PlanController.php" || fail "plan handlers missing CSRF"
grep -q "Csrf::validate(\$_POST\['csrf_token'\]" "$ROOT/app/Modules/Billing/Controllers/PaymentController.php" || fail "payment approval missing CSRF"
pass "admin mutation handlers require CSRF"

# Manual admin mutations were previously an easy CSRF bypass.
awk '/public function addManual\(\)/,/public function grantSubscription\(\)/' "$ROOT/app/Modules/Users/Controllers/UserController.php" | grep -q "Csrf::validate" || fail "manual user creation missing CSRF"
awk '/public function grantSubscription\(\)/,/public function suspend\(\)/' "$ROOT/app/Modules/Users/Controllers/UserController.php" | grep -q "Csrf::validate" || fail "manual subscription grant missing CSRF"
pass "manual admin mutations require CSRF"

# Authentication boundary rotates the CSRF token after session ID rotation.
grep -q "Csrf::rotate();" "$ROOT/app/Core/Auth.php" || fail "CSRF token rotation missing after login"
grep -q "public static function rotate" "$ROOT/app/Core/Csrf.php" || fail "CSRF rotate API missing"
pass "authentication boundary rotates CSRF token"

# Logout is POST-only and itself protected.
grep -q "Router::post('/logout'" "$ROOT/public/index.php" || fail "logout is not POST-only"
grep -q "Csrf::validate(\$_POST\['csrf_token'\]" "$ROOT/app/Controllers/MainController.php" || fail "logout CSRF validation missing"
pass "logout is POST + CSRF protected"

# API responses must not be cacheable and oversized bodies must be rejected before parsing.
grep -q "Cache-Control.*no-store" "$ROOT/app/Api/MobileApiResponse.php" || fail "API no-store header missing"
grep -q "CONTENT_LENGTH" "$ROOT/app/Api/MobileApiRouter.php" || fail "API body-size guard missing"
pass "API response/request boundary hardened"

# All PHP remains syntactically valid.
while IFS= read -r f; do php -l "$f" >/dev/null || fail "PHP syntax: $f"; done < <(find "$ROOT" -type f -name '*.php' -not -path '*/storage/*' | sort)
pass "all PHP syntax checks"

echo "WAVE_J_SECURITY_BOUNDARY_REGRESSION: PASS"
