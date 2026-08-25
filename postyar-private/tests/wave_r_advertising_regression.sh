#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
pass(){ echo "PASS: $1"; }
fail(){ echo "FAIL: $1"; exit 1; }

php -l "$ROOT/app/Domain/Advertising.php" >/dev/null || fail "Advertising domain syntax"
php -l "$ROOT/app/Api/Controllers/AdvertisingApiController.php" >/dev/null || fail "Advertising API syntax"
php -l "$ROOT/app/Controllers/MainController.php" >/dev/null || fail "MainController syntax"

for f in "$ROOT/migrations/v23_advertising.sql" "$ROOT/migrations/v23_advertising_mysql.sql"; do
  grep -q "ad_campaigns" "$f" || fail "campaign table in $f"
  grep -q "ad_events" "$f" || fail "event table in $f"
  grep -q "ad_daily_stats" "$f" || fail "daily stats table in $f"
done
pass "Wave R migration pair exists"

grep -q "'v23_advertising'" "$ROOT/app/Core/Bootstrap.php" || fail "v23 migration registered"
grep -q "Router::post('/ads/impression'" "$ROOT/public/index.php" || fail "web impression route"
grep -q "Router::get('/ads/click/{id}'" "$ROOT/public/index.php" || fail "web click route"
grep -q "MobileApiRouter::get('/ads'" "$ROOT/app/Api/Routes/api.php" || fail "mobile ads route"
grep -q "MobileApiRouter::post('/ads/{id}/impression'" "$ROOT/app/Api/Routes/api.php" || fail "mobile impression route"
pass "Web/PWA/Android advertising routes exist"

grep -q "status='approved'" "$ROOT/app/Domain/Advertising.php" || fail "approval gate"
grep -q "starts_at <= CURRENT_TIMESTAMP" "$ROOT/app/Domain/Advertising.php" || fail "start-time gate"
grep -q "ends_at > CURRENT_TIMESTAMP" "$ROOT/app/Domain/Advertising.php" || fail "end-time gate"
grep -q "http', 'https', 'tg', 'bale" "$ROOT/app/Domain/Advertising.php" || fail "destination scheme allowlist"
! grep -Eq "javascript:|data:|file:" "$ROOT/app/Domain/Advertising.php" || fail "dangerous destination scheme found"
pass "Approval, time-window and destination URL boundaries present"

grep -q "owner_user_id = ?" "$ROOT/app/Domain/Advertising.php" || fail "owner tenant isolation"
grep -q "hash_hmac('sha256'" "$ROOT/app/Domain/Advertising.php" || fail "privacy-preserving fingerprinting"
grep -q "looksLikeBot" "$ROOT/app/Domain/Advertising.php" || fail "bot filtering"
grep -q "uq_ad_event_fingerprint" "$ROOT/migrations/v23_advertising.sql" || fail "atomic unique-event window"
pass "Tenant isolation and anti-abuse telemetry guards present"

grep -q "admin/ads/{id}/status" "$ROOT/app/Api/Routes/api.php" || fail "admin mobile status route"
grep -q "exportAdReport" "$ROOT/public/index.php" || fail "admin CSV route"
pass "Admin approval, statistics and export surfaces exist"

echo "WAVE_R_ADVERTISING_REGRESSION: PASS"
