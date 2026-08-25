# Wave O — Adversarial Abuse & Entitlement Security Gate

## Scope

Wave O treated the application as hostile from the client side and reviewed the highest-risk bypass paths:

- free-trial identity reuse;
- Telegram/Bale channel identity reuse and ownership proof;
- Web/API parity for channel identity mutation;
- first-registration / first-superadmin race;
- password-change session persistence;
- direct subscription activation attempts;
- canonicalization of phone/channel identities;
- regression of Waves G–N.

## Findings and fixes

### O-01 — Bot token was not sufficient proof of target-channel access
A `getMe`-style token validation proves only that the bot token is valid. It does not prove that the bot can access the channel the user is trying to claim.

**Fix:** `ChannelManager::verifyBotChannelAccess()` now calls the platform `getChat` endpoint and verifies that the returned channel identity matches the requested canonical identity. This check runs before an immutable anti-abuse claim is created. Network/API failure is fail-closed.

### O-02 — Mobile channel identity mutation required the same target-access proof
The API channel update path could previously pass the immutable claim check without first proving that the replacement token could access the replacement channel.

**Fix:** when channel identity or bot token changes, the API now performs target-channel access verification before mutating persistent state.

### O-03 — First-superadmin privilege had a concurrency race
A `SELECT COUNT(*)` decision could theoretically allow two concurrent MySQL registrations to both observe an empty user table and both become `superadmin`.

**Fix:** added singleton `system_bootstrap` row and a MySQL `FOR UPDATE` lock. The first successful registration marks the singleton initialized. Existing installations are initialized from the earliest existing user during migration.

### O-04 — Authenticated API password changes did not revoke existing mobile tokens
An old/stolen API token could remain valid after a password change.

**Fix:** authenticated API password changes now revoke all existing API tokens and require re-authentication.

## Database/schema changes

- Added `system_bootstrap` to fresh SQLite and MySQL schemas.
- Added `migrations/v20_adversarial_identity_bootstrap.sql`.
- Registered runtime migration `v20_adversarial_identity_bootstrap` in `Bootstrap.php`.

## Regression evidence

- Wave G: PASS
- Wave H: PASS
- Wave I: PASS
- Wave J: PASS
- Wave L: PASS
- Wave M: PASS
- Wave N: PASS
- Wave O: PASS
- All application PHP syntax checks: PASS
- Fresh `install.sql` execution in Python SQLite: PASS

## Runtime limitation

A real multi-process concurrency test against production MySQL/MariaDB was not executed in this environment because no MySQL/MariaDB server is available. The PHP environment also lacks PDO SQLite for PHP-level runtime concurrency tests. No runtime PASS is claimed for those tests.

## Remaining high-priority work

Wave O does not declare the entire abuse system complete. Remaining work should include production load/concurrency testing, credential-at-rest encryption/secret rotation, platform-specific live Telegram/Bale integration tests, and the complete Web/API/Android entitlement contract.
