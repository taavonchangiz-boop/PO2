# Wave P Production Capacity Runbook

This document is an execution plan, not a fabricated load-test result.

## Required stack
- PHP 8.2+ / PHP-FPM
- MySQL 8.x or MariaDB with InnoDB
- Redis 7+ for multi-node cache/coordination
- Nginx, Apache or LiteSpeed with HTTPS
- CDN/object storage for media where appropriate

## Load profiles
1. Baseline: 100 concurrent users, 15 min.
2. Target: 1,000 concurrent users, 30 min.
3. Stress: 5,000 concurrent users, 30 min.
4. Peak: 10,000 concurrent users, 30 min.
5. Soak: 2,000 concurrent users, 4 hours.

Each profile must mix login/session validation, dashboard reads, post listing, notification reads, channel management, post creation, analytics reads and queue-triggering operations. External Telegram/Bale calls should be stubbed in the first application-capacity run, then separately tested against provider quotas.

## Required measurements
- p50/p95/p99 latency
- 4xx/5xx rate
- PHP-FPM active/max workers and queue depth
- MySQL QPS, CPU, buffer pool hit ratio, connections, lock waits/deadlocks
- Redis hit ratio, latency, memory and evictions
- queue depth/age and retry counts
- outbound provider latency/error rate
- memory growth per worker

## Pass criteria
The exact capacity number is accepted only when measurements satisfy the thresholds in `docs/WAVE-P-REPORT.md` for the agreed workload and no correctness invariant fails.
