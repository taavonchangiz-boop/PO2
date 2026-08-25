#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
fail(){ echo "FAIL: $1" >&2; exit 1; }
pass(){ echo "PASS: $1"; }

php -l "$ROOT/app/Domain/AntiAbuse.php" >/dev/null || fail "AntiAbuse syntax"
php -l "$ROOT/app/Domain/ChannelManager.php" >/dev/null || fail "ChannelManager syntax"
php -l "$ROOT/app/Api/Controllers/ChannelApiController.php" >/dev/null || fail "Channel API syntax"
php -l "$ROOT/app/Controllers/MainController.php" >/dev/null || fail "Web controller syntax"

grep -q 'normalizeChannelId' "$ROOT/app/Domain/AntiAbuse.php" || fail "canonical channel identity missing"
grep -q 'AntiAbuse::claimChannel' "$ROOT/app/Domain/ChannelManager.php" || fail "channel add lacks immutable claim"
grep -q "max_channels" "$ROOT/app/Domain/ChannelManager.php" || fail "channel add lacks entitlement check"
grep -q 'BEGIN IMMEDIATE' "$ROOT/app/Domain/ChannelManager.php" || fail "SQLite atomic gate missing"
grep -q 'FOR UPDATE' "$ROOT/app/Domain/ChannelManager.php" || fail "MySQL row lock missing"
grep -q 'AntiAbuse::claimChannel' "$ROOT/app/Api/Controllers/ChannelApiController.php" || fail "API channel update bypasses claim"
grep -q 'AntiAbuse::claimChannel' "$ROOT/app/Controllers/MainController.php" || fail "web channel update bypasses claim"
if grep -q 'ربات بدون تایید زنده ثبت گردید' "$ROOT/app/Domain/ChannelManager.php"; then fail "network failure still fail-open"; fi
if grep -Rqs 'UPDATE channels SET.*platform.*channel_id' "$ROOT/app" --include='*.php' && ! grep -q 'AntiAbuse::claimChannel' "$ROOT/app/Api/Controllers/ChannelApiController.php"; then fail "direct channel identity mutation bypass"; fi
pass "Wave N entitlement/anti-abuse static gate"
