# Wave H — Anti-Abuse, Free-Trial Claims & Idempotency Hardening

## Objective

Strengthen the highest-risk abuse paths without changing the public Web/API contracts unnecessarily:

1. prevent reuse of a consumed free-trial identity;
2. make Telegram/Bale channel identifiers globally claimable and non-reassignable;
3. make Android/API payment submission retry-safe;
4. make points-to-wallet conversion retry-safe;
5. serialize subscription approval/grant operations per user;
6. make referral registration quota checks transaction-aware.

## Implemented

### 1. Immutable anti-abuse claims

`app/Domain/AntiAbuse.php` introduces DB-backed immutable claims.

- `free_trial_phone`: one normalized phone can claim the free trial once.
- `channel`: one `(platform, channel_id)` can be claimed once.
- Claims survive user deletion because they intentionally do not have a cascading FK to `users`.
- Existing `channel_registry` and user phone data are backfilled by migration v14.

### 2. Registration phone enforcement

`Auth::register()` now requires a valid normalized Iranian mobile number and stores it in `users.phone`.

The Web registration form and Android registration API both require the phone field.

The free-trial claim is reserved **before** user creation inside the same transaction. This closes the concurrent double-registration window for the same phone.

### 3. Channel anti-reuse

`ChannelManager::addChannel()` and Web channel edit now use the immutable channel claim layer plus the existing global registry.

Two simultaneous users attempting the same Telegram/Bale identifier are serialized by the unique DB constraint; the loser is rejected.

### 4. API idempotency

`app/Domain/Idempotency.php` provides a DB-backed idempotency primitive.

Protected operations in this wave:

- `payment_submit`
- `points_convert`

The Android client must send `idempotency_key`. A completed key returns the previous result instead of executing the business operation again.

### 5. Payment duplicate protection

Web and Android payment submission reject reuse of the same `(user_id, reference_num)`.

### 6. Subscription serialization

Admin API payment approval, Web/module payment approval, and manual subscription grants lock the target user row on MySQL (`FOR UPDATE`) before replacing the active subscription.

SQLite uses the equivalent transaction serialization path without emitting unsupported `FOR UPDATE` syntax.

### 7. Referral concurrency

Referral registration now performs the quota check and referral creation inside one transaction, locks the referrer row on MySQL, and relies on the existing `referrals.referred_id UNIQUE` constraint as a second gate.

Reward mutation is no longer silently swallowed inside the transaction.

## Migration

`v14_anti_abuse_idempotency` creates:

- `anti_abuse_claims`
- `idempotency_keys`

Both fresh-install SQL files were updated as well.

## Verification

- PHP syntax: **72/72 PASS**
- Wave G static regression: **PASS**
- Wave H anti-abuse/idempotency regression: **PASS**
- `git diff --check`: not applicable because the supplied snapshot is not itself a Git working tree.
- Runtime SQLite/MySQL concurrency tests: **BLOCKED in this environment** because the PHP CLI has PDO but no PDO SQLite/MySQL driver.

## Important non-claims

This wave does **not** claim that phone ownership is cryptographically/procedurally verified at registration. The current registration flow stores and claims the phone number, but a malicious user can still enter a phone number they do not control unless SMS OTP verification is made mandatory before free-trial activation.

That is intentionally left as a next hardening gate rather than falsely treating a text field as proof of identity.

This wave also does not claim production proof for 5,000/10,000 concurrent users. That requires PHP-FPM + MySQL/InnoDB load testing with realistic connection pools, external-service latency, lock waits, slow queries and failure injection.
