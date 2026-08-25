# Wave P — Performance / Scalability / Load Architecture

## Gate verdict
**PASS — static/structural gate. Real production load is intentionally NOT claimed.**

### Scope
Wave P hardens the hot paths for a target architecture of 5,000–10,000 concurrent users while preserving the existing Web/Mobile API contract.

## Changes

### 1. Shared cache abstraction
`app/Core/Cache.php` provides a non-authoritative cache facade:
- Redis when explicitly enabled and the PHP Redis extension is available.
- APCu when Redis is unavailable.
- Request-local memory as the final fallback.
- Cache failures never become application failures and never replace DB truth.

`GoldTicker` now caches successful upstream prices for 20 seconds. This prevents a large dashboard burst from generating one external request per user.

### 2. Rate-limit hot-path cleanup
Rate-limit cleanup no longer performs an unbounded DELETE on every security-sensitive request. It runs at most once per PHP request and deletes at most 500 expired rows. This prevents maintenance work from becoming the hot-path bottleneck.

### 3. Composite database indexes
Migration `v21_performance_scale_indexes` adds composite indexes matching the actual tenant/status/time access patterns:
- posts tenant/status/created/id
- scheduled posts status/scheduled/id
- delivery ledger post/status/channel
- post-channel statistics
- click analytics
- link tracking/clicks
- wallet transactions
- notifications
- subscriptions
- verification codes
- idempotency records

The migration is registered in `Bootstrap.php` and is idempotent at the deployment layer.

### 4. Cursor pagination for Mobile Posts
`GET /api/v1/posts` retains the old `limit`/`offset` contract but now accepts `before_id` for efficient cursor pagination. Existing response shape is unchanged. Deep offsets are capped at 5,000 to prevent pathological database scans; new Android clients should use `before_id`.

### 5. External-service pressure control
Gold-price fetching is now short-cache protected. The architecture explicitly treats Telegram/Bale/SMS/SMTP/external APIs as bounded outbound dependencies rather than something every web request should fan out to indefinitely.

## 5,000–10,000 user production architecture

The PHP application remains stateless at the application layer except for session storage. For the target scale:

1. HTTPS reverse proxy/load balancer.
2. 2+ PHP-FPM application workers/instances.
3. MySQL 8/MariaDB with InnoDB as the production database; SQLite is for single-node/small deployments and development only.
4. Redis for shared cache/rate-limit/short-lived coordination when multiple app instances are used.
5. Cron/worker execution on a dedicated worker node or a single scheduler with distributed locking.
6. Telegram/Bale delivery must be queue-backed and bounded by provider rate limits; web requests should not wait on large fan-out jobs.
7. Object/media storage should be externalized or served by the web server/CDN for large deployments.
8. Slow-query logging and database metrics must be enabled before declaring production capacity.

## Capacity policy

No claim is made that the current environment has been load-tested to 10,000 concurrent users. A real capacity gate requires a PHP-FPM + MySQL/Redis environment and representative traffic. The acceptance target should be measured, not guessed:

- p95 API latency target: <= 500 ms for DB-only reads under normal load.
- p95 write latency target: <= 800 ms excluding external provider delivery.
- Error rate: < 0.1% for application-generated 5xx under the agreed load profile.
- Database CPU, connection utilization and lock waits must remain below the agreed operational threshold.
- Queue lag must remain bounded and recover after a worker restart.
- External provider calls must not multiply linearly with dashboard/user refresh traffic when a cached value is valid.

## Known environmental limitation

The current execution environment exposes PHP PDO but not PDO SQLite, and no MySQL/MariaDB or Redis server is available. Therefore true multi-process database contention, query plans, PHP-FPM saturation and 5,000/10,000-user load cannot be truthfully marked PASS here.

## Regression

- `tests/wave_p_performance_scalability_regression.sh` — PASS
- All PHP files under `app/` — syntax PASS
- Wave O regression — PASS

## Next gate

Wave Q should focus on **real observability + production deployment + queue architecture + load-test harness + API/Web parity**, followed by actual execution in a PHP 8.2+/MySQL/Redis environment.
