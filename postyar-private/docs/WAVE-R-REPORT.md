# Wave R — Advertising End-to-End

## Status
**PASS — static/structural gate passed.** Runtime DB integration remains environment-dependent because the current PHP build has no PDO SQLite/MySQL driver loaded.

## Delivered
- Campaign lifecycle: `pending → approved/rejected/paused/archived`.
- Hard time window: only `approved` + `starts_at <= now < ends_at` is publicly visible.
- Secure image upload through the existing MIME validation/WebP conversion path.
- Destination URL allowlist: `http`, `https`, `tg`, `bale`; no `javascript:`, `data:`, `file:` or protocol-relative URLs.
- Public click redirect through a server-owned campaign ID; the browser never redirects directly from untrusted form input.
- Impression and click telemetry.
- Privacy-preserving HMAC fingerprints; raw IP and user-agent are not stored in ad telemetry.
- Atomic unique-event protection using a DB unique index plus `INSERT IGNORE` / `INSERT OR IGNORE`.
- Unique telemetry bucket is one campaign/event/fingerprint per UTC day; aggregate counters remain total + unique.
- Obvious bot/crawler user agents are excluded from telemetry.
- Daily aggregate table for scalable reporting instead of recalculating all raw events for every report.
- Owner dashboard: create campaign, status, date-filtered statistics and archive.
- Admin dashboard: approval/rejection/pause/archive, aggregate statistics, date filtering and CSV export.
- Android/PWA API: active ads, create ad, owner campaigns, owner stats, impression/click recording, admin listing/status.
- Fresh-install schema + versioned v23 upgrade migration for SQLite and MySQL.
- Event pruning primitive retained for a later worker/maintenance job.

## Security boundaries
1. Owner queries are constrained by `owner_user_id`.
2. Admin state transitions require super-admin authorization and CSRF on Web.
3. Public click IDs are resolved server-side to an already-approved campaign.
4. Campaign destination URLs are revalidated immediately before redirect.
5. No raw client IP/UA is persisted in `ad_events`.
6. Unique-event counting is DB-atomic, reducing concurrent double-count races.
7. Daily stats use atomic upsert semantics.

## Regression
`tests/wave_r_advertising_regression.sh` passes.
All existing Waves G, H, I, J, K, L, M, N, O, P and Q regression gates also pass after Wave R changes.

## Runtime limitation
The environment has PHP CLI but no PDO SQLite/MySQL driver. Therefore actual SQL execution, concurrent database behavior and browser rendering cannot honestly be marked runtime-verified here. Those must be executed in the target PHP 8.2+/SQLite or MySQL production-like environment before production release.
