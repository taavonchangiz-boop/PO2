# Wave L — Concurrency, Transaction Integrity & Delivery Safety

## Verdict
**PASS (static/structural gate), with runtime DB concurrency testing blocked by the current PHP environment.**

Wave L targets the highest-risk race conditions after tenant isolation: quota oversubscription, duplicate queue execution, duplicate delivery ledger rows, rate-limit TOCTOU races, and overlapping cron workers.

## Implemented

### 1. Atomic rate limiting
`app/Core/RateLimit.php`
- Replaced read-then-write attempt counting with a conditional atomic `UPDATE`.
- Uses the unique `(ip, action)` invariant.
- Concurrent requests cannot both pass the same remaining-attempt check.
- Preserved the legacy `check()`, `hit()`, and `clear()` APIs.

### 2. Transaction primitive
`app/Core/Transaction.php`
- Added a small retry-aware transaction helper for transient SQLite/MySQL contention.
- Retries only known transient lock/deadlock conditions.
- Never swallows application exceptions.

### 3. Atomic post quota reservation
`app/Domain/Quota.php`
- `sending` is now a quota reservation state.
- MySQL locks the active subscription row with `FOR UPDATE`.
- SQLite uses `BEGIN IMMEDIATE` for the short reservation transaction.
- Quota counts both `sent` and `sending` posts.
- Finalization requires `status='sending'` and tenant ownership.

### 4. Queue and scheduled-post claims
- Web/AJAX queue now atomically claims a queued post before external delivery.
- Scheduled jobs atomically claim scheduled posts before delivery.
- Mobile instant-send and retry paths use the same reservation invariant.
- Gold ticker publication paths no longer bypass post quota.

### 5. Delivery ledger uniqueness
`migrations/install.sql` / `migrations/install_mysql.sql`
- Added unique `(post_id, channel_id)` delivery invariant.

`app/Core/Bootstrap.php` — `v17_concurrency_delivery_integrity`
- Deduplicates legacy delivery rows before adding the unique invariant.
- Adds a hot-path status index.

`app/Domain/Sender.php`
- Skips a channel already recorded as successfully sent.
- Uses SQLite/MySQL upsert semantics for the delivery ledger.

### 6. Cron process lock
`cron.php`
- Added non-blocking `flock(LOCK_EX | LOCK_NB)` around the complete cron process.
- Prevents overlapping cPanel/minute cron executions on the same host.
- Runtime lock file is ignored by git.

### 7. Regression gate
`tests/wave_l_concurrency_regression.sh`
- Verifies all critical Wave L invariants.
- Runs PHP syntax checks across application/test PHP files.

## Verification

- Wave L static regression: **PASS**
- PHP syntax: **PASS — 73 PHP files checked by the Wave L gate**
- Direct grep audit: no unguarded `posts -> sent` transition remains outside `Quota::consumePostQuota()`.

## Runtime limitation
The current environment has PHP CLI but only the PDO core module; **PDO SQLite is not installed**. Therefore a true multi-process SQLite lock/transaction stress test cannot honestly be reported as passed here.

This is a test-environment limitation, not a claim that runtime concurrency has been proven.

## Important residual limitation
External messaging APIs are inherently an at-least-once boundary. If the process dies after Telegram/Bale accepts a message but before the local delivery ledger is finalized, a later retry cannot mathematically prove whether the remote side accepted the previous request. Wave L therefore prevents ordinary concurrent duplicates and duplicate ledger rows, but does **not** claim impossible exactly-once delivery across an external network failure window.

The safe policy is to treat stale `sending` records as **ambiguous**, not blindly auto-retry them. A later wave should add operational reconciliation/observability for this state rather than introduce a duplicate-send-prone automatic reset.

## Files changed in Wave L
- `app/Core/RateLimit.php`
- `app/Core/Transaction.php`
- `app/Domain/Quota.php`
- `app/Domain/ScheduledPost.php`
- `app/Domain/Sender.php`
- `app/Api/Controllers/PostApiController.php`
- `app/Api/Controllers/SettingsApiController.php`
- `app/Controllers/MainController.php`
- `app/Domain/GoldTicker.php`
- `app/Core/Bootstrap.php`
- `migrations/install.sql`
- `migrations/install_mysql.sql`
- `cron.php`
- `.gitignore`
- `tests/wave_l_concurrency_regression.sh`
- `docs/WAVE-L-REPORT.md`
