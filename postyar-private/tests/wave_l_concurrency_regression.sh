#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
fail(){ echo "FAIL: $1" >&2; exit 1; }
grep -q "UPDATE rate_limits" "$ROOT/app/Core/RateLimit.php" || fail "rate limiter atomic update missing"
grep -q "attempts < ?" "$ROOT/app/Core/RateLimit.php" || fail "rate limiter conditional gate missing"
grep -q "uq_rate_limits_ip_action" "$ROOT/migrations/install.sql" || fail "rate limit unique index missing"
grep -q "BEGIN IMMEDIATE" "$ROOT/app/Domain/Quota.php" || fail "SQLite immediate transaction missing"
grep -q "FOR UPDATE" "$ROOT/app/Domain/Quota.php" || fail "MySQL row lock missing"
grep -q "status IN ('sent','sending')" "$ROOT/app/Domain/Quota.php" || fail "in-flight quota reservation missing"
grep -q "reservePost" "$ROOT/app/Domain/ScheduledPost.php" || fail "scheduled job claim missing"
grep -q "reservePost" "$ROOT/app/Controllers/MainController.php" || fail "web queue claim missing"
grep -q "reservePost" "$ROOT/app/Api/Controllers/PostApiController.php" || fail "mobile post claim missing"
grep -q "uq_channel_messages_post_channel" "$ROOT/migrations/install.sql" || fail "delivery uniqueness missing"
grep -q "uq_channel_messages_post_channel" "$ROOT/migrations/install_mysql.sql" || fail "MySQL delivery uniqueness missing"
grep -q "already_sent" "$ROOT/app/Domain/Sender.php" || fail "duplicate delivery guard missing"
grep -q "v17_concurrency_delivery_integrity" "$ROOT/app/Core/Bootstrap.php" || fail "v17 migration missing"
grep -q "flock(\$cronLock, LOCK_EX | LOCK_NB)" "$ROOT/cron.php" || fail "global cron process lock missing"
grep -q "/storage/cron.lock" "$ROOT/.gitignore" || fail "cron lock file is not ignored"
# No production path may finalize a post to sent without first being in sending.
if grep -RIn --include='*.php' "UPDATE posts SET status = 'sent' WHERE id = ?" "$ROOT/app" | grep -v 'Domain/Quota.php' >/dev/null; then
  fail "unguarded post sent transition found"
fi
count=0
while IFS= read -r -d '' f; do
  php -l "$f" >/dev/null || fail "PHP syntax: $f"
  count=$((count+1))
done < <(find "$ROOT/app" "$ROOT/tests" -type f -name '*.php' -print0)
echo "PASS: Wave L static concurrency checks ($count PHP files syntax-valid)"
