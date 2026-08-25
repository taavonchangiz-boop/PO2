# Wave X1 — cPanel/MariaDB Migration Idempotency Fix

## Root cause fixed

`migrations/install_mysql.sql` already contained the v15 hardening indexes while `schema_migrations` only recorded the historical migrations through v14. On the first web request after installation, `Bootstrap::runVersionedMigrations()` attempted to create those same indexes again. MariaDB raised a duplicate-index PDOException and the production error handler correctly reduced it to the generic Persian system-error page.

## Fix

The v15 migration now checks `INFORMATION_SCHEMA.STATISTICS` before creating:

- `uq_rate_limits_ip_action`
- `idx_verification_user_type_used`
- `uq_users_phone`

An already-present index is treated as an already-applied schema operation; the migration can then be recorded normally in `schema_migrations`.

No database data is deleted by this fix.
