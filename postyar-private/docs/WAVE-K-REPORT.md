# Wave K — Tenant Isolation, IDOR, Webhook Boundary & Production Query Hardening

## Scope

Wave K targets the highest-risk authorization boundaries rather than treating earlier passing waves as sufficient.

### Changes implemented

1. **Public click endpoint hardening**
   - `/click?p=&c=` now verifies that the channel belongs to the same tenant as the post.
   - The channel must also be present in the post's `target_channels` list.
   - Arbitrary `post_id/channel_id` combinations can no longer manufacture analytics events or expose another tenant's `link_config`.

2. **Webhook authentication hardening**
   - Every channel webhook now requires its cryptographically random `webhook_secret`.
   - Telegram continues to support its native secret-token header.
   - Bale/other webhook requests use the channel secret in the webhook URL.
   - Legacy channels without a secret receive a generated secret and are marked `webhook_active=0` until the webhook is re-registered. This is fail-closed rather than silently accepting forged webhook traffic.
   - Webhook state updates are tenant-scoped.

3. **Mobile API authentication schema**
   - `api_tokens` is now automatically created by the versioned migration and included in the fresh SQLite install schema.
   - Mobile API authentication no longer depends on an operator manually applying `mobile_api.sql`.

4. **Tenant/query indexes**
   - Added indexes for tenant + object/status/scheduling access paths covering channels, posts, tickets, payments, link tracking and auto-replies.
   - SQLite gets idempotent indexes directly; MySQL gets driver-aware versioned creation.

5. **Static authorization gate**
   - Added `tests/wave-k-tenant-isolation.php`.
   - It verifies critical ownership predicates, public click binding, webhook secret validation, API-token installation, and migration presence.

## Verification

- PHP syntax: **74 PHP files — PASS**
- Wave K static tenant-isolation gate: **PASS**
- Source tree was not modified outside this working copy.
- Runtime database concurrency testing: **BLOCKED in this environment** because the available PHP build exposes PDO but not the SQLite PDO driver.

## Important residual limitation

The webhook secret for an existing legacy webhook cannot be retroactively transmitted to Telegram/Bale without re-registering the remote webhook. Therefore Wave K deliberately disables legacy secret-less webhooks during migration rather than retaining an unauthenticated endpoint. Re-registration must occur as part of deployment/health-check provisioning.

## Security posture

Wave K is considered complete only for the static/source-level gate. It is **not** a claim that production authorization is mathematically proven without executing an integration test matrix against a real SQLite/MySQL database and two separate authenticated users.
