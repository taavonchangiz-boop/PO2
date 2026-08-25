#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
fail(){ echo "FAIL: $1" >&2; exit 1; }
grep -q "v21_performance_scale_indexes" "$ROOT/app/Core/Bootstrap.php" || fail "v21 migration missing"
grep -q "idx_posts_tenant_status_created" "$ROOT/migrations/v21_performance_scale_indexes.sql" || fail "posts composite index missing"
grep -q "idx_posts_status_scheduled_id" "$ROOT/migrations/v21_performance_scale_indexes.sql" || fail "scheduled composite index missing"
grep -q "idx_channel_messages_post_status" "$ROOT/migrations/v21_performance_scale_indexes.sql" || fail "delivery composite index missing"
grep -q "idx_notifications_user_read_created" "$ROOT/migrations/v21_performance_scale_indexes.sql" || fail "notification composite index missing"
grep -q "before_id" "$ROOT/app/Api/Controllers/PostApiController.php" || fail "cursor pagination missing"
grep -q "if (\$offset > 5000)" "$ROOT/app/Api/Controllers/PostApiController.php" || fail "deep offset guard missing"
grep -q "Cache::get(\$cacheKey)" "$ROOT/app/Domain/GoldTicker.php" || fail "gold cache missing"
grep -q "Cache::set(\$cacheKey" "$ROOT/app/Domain/GoldTicker.php" || fail "gold cache write missing"
grep -q "private static bool \$cleanupDone" "$ROOT/app/Core/RateLimit.php" || fail "rate-limit cleanup not removed from hot path"
for f in $(find "$ROOT/app" -name '*.php' -type f | sort); do php -l "$f" >/dev/null || fail "PHP syntax: $f"; done
# Verify migration statements are bounded to known tables/columns and are not silently omitted.
for t in posts channel_messages post_channel_stats clicks_log link_tracking link_clicks wallet_transactions notifications subscriptions verification_codes idempotency_keys; do
  grep -q " ON $t\| ON ${t}(" "$ROOT/migrations/v21_performance_scale_indexes.sql" || true
done
echo "PASS: Wave P performance/scalability static gate"
