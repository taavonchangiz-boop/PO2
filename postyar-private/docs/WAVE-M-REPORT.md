# Wave M — Financial & Subscription Integrity

## Objective

Harden the financial boundary against client-side amount tampering, duplicate payment references, concurrent payment approval, duplicate referral rewards, wallet transaction nesting, and abandoned idempotency reservations.

## Implemented

1. Added `PaymentPricing` as the server-authoritative subscription pricing calculator.
2. Web and Mobile API payment submission ignore client authority over `amount`; the server calculates and persists the amount.
3. Added `payments.quoted_amount` as a price snapshot at submission time.
4. Added `PaymentSettlement` as the single approval path for both web admin and Mobile API admin approval.
5. Payment approval is atomic: payment claim, user locking, subscription replacement, and first-purchase referral settlement occur in one DB transaction.
6. MySQL settlement locks the user/payment rows with `FOR UPDATE`; SQLite uses the existing transaction/retry mechanism.
7. Referral first-purchase reward now fails the complete settlement if the wallet ledger cannot be written.
8. Wallet `credit()` and `debit()` now safely participate in an existing outer transaction instead of attempting nested PDO transactions.
9. Added DB uniqueness for `(user_id, reference_num)` payment references.
10. Added DB uniqueness for non-null wallet business references.
11. Added stale idempotency recovery after 30 minutes so a crashed worker cannot permanently poison a key.
12. Added v18 financial integrity migration and auditable SQL reference.
13. Fixed a duplicate `sent_at` column in the MySQL fresh-install schema and restored `subscriptions.expiry_reminder_sent` parity.
14. Updated regression tests so they assert the new shared settlement architecture rather than obsolete controller-local SQL.

## Verification

- 80 PHP files syntax-valid in the Wave M source tree.
- Wave G regression: PASS.
- Wave H regression: PASS.
- Wave I regression: PASS.
- Wave J regression: PASS.
- Wave L regression: PASS.
- Wave M financial integrity regression: PASS.
- SQLite `install.sql` executes successfully in Python sqlite3.
- `mobile_api.sql` executes successfully in Python sqlite3.
- MySQL fresh-schema duplicate-column scan: PASS.

## Runtime limitation

The current execution environment exposes PHP/PDO but **does not expose PDO SQLite or a MySQL/MariaDB server**. Therefore no claim of multi-process DB runtime success is made here. Production concurrency testing remains a required deployment-stage gate.

## Important design boundary

This wave does **not** claim mathematically guaranteed exactly-once delivery to external payment/bot providers. External side effects require reconciliation semantics. The financial database state itself is now transactionally coordinated around the payment approval boundary.
