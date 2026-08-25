# Wave N — Entitlement, Anti-Abuse & Subscription-Bypass Gate

## Verdict
**PASS (static/structural gate).** Runtime multi-process database tests remain environment-blocked because the current PHP environment does not provide PDO SQLite and no MySQL server is available.

## Scope
Wave N audits the boundary between Web, Mobile API, subscription entitlements and permanent anti-abuse identities. A user must not bypass limits by changing endpoint, retrying, using another session/device, changing identifier formatting, or mutating an existing channel through a weaker API path.

## Findings addressed

### N-001 — API channel update bypassed immutable channel claims
`ChannelApiController::update()` previously checked `channel_registry` but did not use the same immutable `anti_abuse_claims` gate used by channel creation and the Web controller. It now canonicalizes the identity and claims it atomically before changing platform/channel identity.

### N-002 — Identity canonicalization bypass (`@Foo` vs `Foo` / case variants)
`AntiAbuse::normalizeChannelId()` and `normalizePlatform()` now define the canonical identity used by all new channel add/update paths. This prevents superficial formatting differences from producing different anti-abuse hashes.

### N-003 — Channel quota race at creation
Channel creation now performs active-subscription lookup, `max_channels` enforcement, immutable identity claim, registry ownership and channel INSERT in one short transaction. MySQL uses `FOR UPDATE`; SQLite uses `BEGIN IMMEDIATE`.

### N-004 — Network failure was previously fail-open
A timeout/DNS/connectivity failure during bot verification previously allowed registration with a warning. That was unsafe: an attacker could potentially claim a legitimate channel identifier without proving control. Wave N changes this to **fail closed**. A channel identity is never permanently claimed merely because external verification is unavailable.

### N-005 — Historical identity normalization
`v19_entitlement_identity_integrity` canonicalizes historical `channel_registry` and channel-type `anti_abuse_claims` identities. If two historical representations collapse to the same canonical identity but have different owners, migration stops and requires manual resolution rather than guessing.

## Invariants now enforced

1. A free-trial phone claim is immutable and survives account deletion.
2. A channel identity claim is immutable and survives account deletion.
3. `@foo`, `foo`, and case variants resolve to the same canonical identity.
4. Web channel creation and Mobile API channel creation share the same quota/claim model.
5. Mobile API channel identity changes cannot bypass anti-abuse ownership.
6. Channel creation cannot exceed the active plan's `max_channels` under concurrent requests.
7. External verification failure cannot consume a permanent channel claim.
8. No controller may treat client-supplied entitlement state as authoritative.

## Verification
- PHP syntax: **77 files, 0 failures**
- Wave G regression: **PASS**
- Wave H regression: **PASS**
- Wave I regression: **PASS**
- Wave J regression: **PASS**
- Wave L regression: **PASS**
- Wave M regression: **PASS**
- Wave N regression: **PASS**

## Runtime limitation
The environment exposes PDO but not PDO SQLite, and no MySQL/MariaDB server is available. Therefore true multi-process database contention tests are not claimed as PASS. They must be executed in the production-like PHP 8.2+ environment before release.

## Security posture decision
This wave intentionally prefers **fail-closed** over availability when ownership of an external channel cannot be verified. A temporary network outage must not become an anti-abuse bypass or a permanent identity-grabbing primitive.
