#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
fail(){ echo "FAIL: $1" >&2; exit 1; }
pass(){ echo "PASS: $1"; }

php -l "$ROOT/app/Domain/AntiAbuse.php" >/dev/null || fail "AntiAbuse syntax"
php -l "$ROOT/app/Domain/ChannelManager.php" >/dev/null || fail "ChannelManager syntax"
php -l "$ROOT/app/Core/Auth.php" >/dev/null || fail "Auth syntax"
php -l "$ROOT/app/Core/Bootstrap.php" >/dev/null || fail "Bootstrap syntax"
php -l "$ROOT/app/Api/Controllers/AuthApiController.php" >/dev/null || fail "Auth API syntax"
php -l "$ROOT/app/Api/Controllers/ChannelApiController.php" >/dev/null || fail "Channel API syntax"

php "$ROOT/tests/wave_o_identity_unit.php" >/dev/null || fail "canonical identity normalization"
# A valid bot token alone is not enough to claim a channel.
grep -q 'verifyBotChannelAccess' "$ROOT/app/Domain/ChannelManager.php" || fail "channel access proof missing"
grep -q '/getChat?' "$ROOT/app/Domain/ChannelManager.php" || fail "target-channel API verification missing"
grep -q 'ChannelManager::verifyBotChannelAccess' "$ROOT/app/Api/Controllers/ChannelApiController.php" || fail "API channel update bypasses target access proof"

# First-admin privilege must be protected by a DB singleton lock, not COUNT(*).
grep -q 'system_bootstrap' "$ROOT/app/Core/Auth.php" || fail "first-admin bootstrap guard missing"
grep -q 'FOR UPDATE' "$ROOT/app/Core/Auth.php" || fail "MySQL bootstrap row lock missing"
if grep -q 'SELECT COUNT(*) as cnt FROM users' "$ROOT/app/Core/Auth.php"; then fail "unsafe first-admin COUNT race remains"; fi

# Password changes invalidate old API sessions.
grep -q 'revokeAllUserTokens' "$ROOT/app/Api/Controllers/AuthApiController.php" || fail "API password change does not revoke sessions"

# Fresh-install schemas must contain the bootstrap guard.
grep -q 'CREATE TABLE IF NOT EXISTS system_bootstrap' "$ROOT/migrations/install.sql" || fail "SQLite fresh install missing bootstrap guard"
grep -q 'CREATE TABLE IF NOT EXISTS system_bootstrap' "$ROOT/migrations/install_mysql.sql" || fail "MySQL fresh install missing bootstrap guard"

grep -q "v20_adversarial_identity_bootstrap" "$ROOT/app/Core/Bootstrap.php" || fail "v20 migration not registered"

test -f "$ROOT/migrations/v20_adversarial_identity_bootstrap.sql" || fail "v20 SQL artifact missing"

# No direct user-controlled subscription activation path in web/API controllers.
if grep -RqsE "INSERT INTO subscriptions.*\$_(POST|GET)|UPDATE subscriptions SET status = 'active'.*\$_(POST|GET)" "$ROOT/app/Controllers" "$ROOT/app/Api/Controllers" --include='*.php'; then
  fail "user-controlled direct subscription activation detected"
fi

# Syntax gate for the whole application.
while IFS= read -r f; do php -l "$f" >/dev/null || fail "PHP syntax: $f"; done < <(find "$ROOT/app" -type f -name '*.php' | sort)

pass "Wave O adversarial abuse/security static gate"
