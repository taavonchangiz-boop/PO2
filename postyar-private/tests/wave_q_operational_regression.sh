#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
fail(){ echo "FAIL: $1" >&2; exit 1; }
php -l "$ROOT/app/Core/RequestContext.php" >/dev/null || fail request_context_syntax
php -l "$ROOT/app/Core/Logger.php" >/dev/null || fail logger_syntax
php -l "$ROOT/app/Core/Metrics.php" >/dev/null || fail metrics_syntax
php -l "$ROOT/app/Domain/JobQueue.php" >/dev/null || fail queue_syntax
php -l "$ROOT/app/Controllers/HealthController.php" >/dev/null || fail health_syntax
php -l "$ROOT/app/Api/Controllers/HealthApiController.php" >/dev/null || fail api_health_syntax
php -l "$ROOT/worker.php" >/dev/null || fail worker_syntax
grep -q "v22_observability_jobs" "$ROOT/app/Core/Bootstrap.php" || fail migration_registration
grep -q "Router::get('/healthz'" "$ROOT/public/index.php" || fail web_health_route
grep -q "Router::get('/readyz'" "$ROOT/public/index.php" || fail web_ready_route
grep -q "MobileApiRouter::get('/health'" "$ROOT/app/Api/Routes/api.php" || fail api_health_route
grep -q "MobileApiRouter::get('/ready'" "$ROOT/app/Api/Routes/api.php" || fail api_ready_route
grep -Fq "worker\.php" "$ROOT/.htaccess" || fail worker_web_block
grep -q "No registered handler for job type" "$ROOT/worker.php" || fail queue_allowlist
# Ensure no dynamic callable is built from a DB-provided job type.
if grep -Eq 'call_user_func.*job|eval\(|include.*\$job\[.type' "$ROOT/worker.php"; then fail unsafe_dynamic_job_dispatch; fi
for f in $(find "$ROOT/app" -name '*.php' -type f | sort); do php -l "$f" >/dev/null || fail "PHP syntax: $f"; done
echo "PASS: Wave Q operational/observability static gate"
