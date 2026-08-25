# Wave W — Scale & Concurrency Torture Gate

## Objective

The platform is expected to support a production deployment targeting 5,000–10,000 concurrently active users. This does **not** mean a single PHP process or SQLite file can safely serve that load. The application must use a production-grade MySQL/InnoDB deployment, multiple PHP workers, and shared Redis where cross-request coordination/cache is required.

## Hardening implemented

- SQLite `busy_timeout=5000`, WAL mode request, foreign keys ON, `synchronous=NORMAL`.
- MySQL native PDO prepares (`ATTR_EMULATE_PREPARES=false`).
- Transient transaction retry for SQLite busy/deadlock/lock-wait conditions.
- Durable job queue leases now carry a cryptographic `lease_token` fence. A stale worker cannot complete/fail a job after another worker has reclaimed it.
- MySQL queue claims use `FOR UPDATE SKIP LOCKED`.
- Post quota reservation remains an atomic state transition protected by the subscription row lock (MySQL) or `BEGIN IMMEDIATE` (SQLite).
- Rate limiting remains database-backed and uses conditional update + unique `(ip, action)` protection.
- Added operational indexes for job lease fencing and rate-limit cleanup.

## Non-negotiable production topology

For the 5k–10k target, production must not rely on SQLite as the primary concurrent database. SQLite remains useful for local/small deployments and installation bootstrap, but the scale target requires:

1. MySQL 8+/InnoDB.
2. Multiple PHP-FPM/LiteSpeed workers.
3. Redis for shared cache/counters and future distributed coordination.
4. Durable worker processes for asynchronous jobs.
5. Database connection limits sized below the MySQL server's max connections.
6. Monitoring for DB latency, lock waits, queue age, PHP worker saturation and external API latency.

## What is NOT claimed yet

This gate is a structural/forensic gate, not a synthetic benchmark. It does **not** certify 10,000 concurrent users. Real certification requires a staging environment with the production topology and controlled load tests covering login, API, dashboard, posting, scheduled jobs, ad impressions/clicks, OTP, and payment callbacks.

## Release blockers remaining

- Real MySQL concurrency test.
- Redis failover/latency test.
- PHP-FPM saturation test.
- Queue duplicate-side-effect test with external providers mocked.
- Scheduled-post race test across multiple workers.
- 5k/10k load test with p95/p99 latency and error-rate thresholds.
