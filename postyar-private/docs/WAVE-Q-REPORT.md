# Wave Q — Operational Readiness, Queue Foundation & Web/Mobile Parity

## Gate verdict
**PASS — static/structural gate. Production capacity is NOT claimed.**

### What was hardened
1. **Request correlation:** every request receives a validated `X-Request-ID`; generated IDs are cryptographically random and echoed in responses/log context.
2. **Structured operational logging:** `Logger` writes JSONL under `storage/logs` (outside the public document root in the intended deployment) and redacts common credential fields.
3. **Health/readiness probes:** `/healthz` and `/readyz` for web, plus `/api/v1/health` and `/api/v1/ready` for Android/PWA clients. Readiness checks the DB without exposing schema or credentials.
4. **Protected metrics endpoint:** `/metrics` requires a dedicated secret header and returns Prometheus-compatible text from request-local counters. It is intentionally not exposed without an operator token.
5. **Durable queue foundation:** `jobs` table, claim/lease/retry/dead-letter lifecycle, bounded attempts and an explicit allow-list boundary in `worker.php`. No DB value is ever executed as PHP code.
6. **Deployment safety:** `worker.php` is blocked from web access and is intended to run only from CLI/worker infrastructure.
7. **Migration parity:** v22 has both SQLite and MySQL definitions and is registered in `Bootstrap.php`.

### Important limitation
The queue is a **foundation**, not a claim that every existing external side effect has already been moved to asynchronous execution. Handlers must be explicitly registered and tested before production use. This avoids creating a false sense of safety around Telegram/Bale/SMS/SMTP delivery.

### Scale posture
For 5,000–10,000 users, the recommended production topology remains PHP-FPM + MySQL/InnoDB + Redis + dedicated worker(s) + reverse proxy/CDN. Wave Q makes operational boundaries explicit; it does not fabricate a load-test result.

### Regression
- `tests/wave_q_operational_regression.sh` — PASS
- All PHP files under `app/` — syntax checked
- Prior Wave P regression suite should remain a required release gate.

### Android contract
Android clients should use `/api/v1/` only and treat `X-Request-ID` as a support/debug correlation value. Health/readiness endpoints are unauthenticated and intentionally minimal. Business endpoints continue to require the existing auth/superadmin scopes.

### Next gate
Wave R should implement and verify the **advertising subsystem end-to-end** (campaign scheduling, image/link validation, impression/click attribution, anti-fraud rules, owner/admin reporting, archive, Web/PWA/Android parity) and then perform another adversarial regression pass across all prior waves.
