-- Wave M — financial/payment integrity hardening
-- Bootstrap executes the equivalent driver-specific migration automatically.
-- This file is an auditable reference for production DBAs.

-- 1) Preserve the server-authoritative quote used when a payment was submitted.
ALTER TABLE payments ADD COLUMN quoted_amount DECIMAL(12,2) NULL;
UPDATE payments SET quoted_amount = amount WHERE quoted_amount IS NULL;

-- 2) Prevent reuse of the same bank reference by the same user.
-- SQLite production installs use a partial unique index; MySQL uses a normal unique index.
-- Apply the variant matching the database engine.

-- SQLite:
-- CREATE UNIQUE INDEX IF NOT EXISTS uq_payments_user_reference
--   ON payments(user_id, reference_num)
--   WHERE reference_num IS NOT NULL AND reference_num <> '';

-- MySQL:
-- CREATE UNIQUE INDEX uq_payments_user_reference
--   ON payments(user_id, reference_num);

-- 3) Prevent duplicate wallet ledger entries for the same business reference.
-- SQLite:
-- CREATE UNIQUE INDEX IF NOT EXISTS uq_wallet_reference
--   ON wallet_transactions(user_id, reference_type, reference_id, type)
--   WHERE reference_type IS NOT NULL AND reference_id IS NOT NULL;

-- MySQL:
-- CREATE UNIQUE INDEX uq_wallet_reference
--   ON wallet_transactions(user_id, reference_type, reference_id, type);
