# Wave G — Database / Transaction Integrity & Concurrency Hardening

## Scope

This wave targets state transitions and balance mutations that can be reached concurrently. The objective is to make the **database write itself** enforce the invariant rather than relying on a prior `SELECT`.

## Changes

### 1. Wallet debit
`Wallet::debit()` now performs:

```sql
UPDATE users
SET wallet_balance = wallet_balance - ?
WHERE id = ? AND wallet_balance >= ?
```

The affected-row count must be exactly one. This closes the classic read-check-write race where two requests can both observe the same balance.

### 2. Referral points conversion
`Wallet::convertPointsToWallet()` now atomically claims the requested points using an `UPDATE ... WHERE referral_points >= ?` predicate before crediting the wallet, all inside one transaction.

### 3. First-purchase referral reward
`Referral::processFirstPurchase()` now claims a pending referral with:

```sql
UPDATE referrals ... WHERE id = ? AND status = 'pending'
```

Only the request that changes one row may issue the reward. A second concurrent request becomes a no-op.

### 4. Payment approval idempotency
All three existing approval paths now require `status = 'pending'` in the approval UPDATE and require one affected row:

- Mobile/Admin API
- Web MainController
- Billing module controller

This prevents double approval and duplicate subscription issuance from two concurrent approval requests.

### 5. Hot-path indexes
Migration `v13_concurrency_indexes` adds indexes for:

- payments(status, created_at)
- subscriptions(user_id, status)
- subscriptions(status, end_date)
- wallet_transactions(user_id, created_at)
- referrals(referred_id, status)
- rate_limits(action, ip)

Index creation is best-effort/idempotent for upgrades because an existing production database may already contain one of these indexes.

## Verification

- PHP syntax: **70/70 PASS**
- Static concurrency invariant suite: **PASS (7/7)**
- SQLite dynamic concurrency test: **BLOCKED in this sandbox because PDO SQLite driver is not installed**.

The dynamic test is intentionally retained at `tests/wave_g_concurrency_regression.php`; it must be executed in the PHP deployment/CI image with PDO SQLite enabled and, separately, against real MySQL/InnoDB.

## Important non-claims

This wave does **not** claim that 5,000/10,000-user production concurrency has been proven. That requires an actual PHP-FPM + MySQL/InnoDB environment and load testing with realistic traffic, connection pools, locks, slow queries, and external-service latency.
