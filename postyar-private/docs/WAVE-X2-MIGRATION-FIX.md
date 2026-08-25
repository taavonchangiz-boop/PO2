# Wave X2 — Migration repair

This release fixes the production migration failure observed on MySQL/cPanel.

## Root causes fixed

1. `v21_performance_scale_indexes` indexed `clicks_log.created_at`, but the canonical schema uses `clicks_log.clicked_at`.
2. The MySQL base schema did not create `notifications`.
3. The old v12 notifications callback used SQLite-only `AUTOINCREMENT` SQL and swallowed the MySQL error, allowing v12 to be recorded as successful while the table remained absent.
4. `schema_migrations` uses `version`, not `migration_name`.

## Repair behavior

- Fresh MySQL installs now create `notifications` directly in `install_mysql.sql`.
- v12 now has explicit MySQL and SQLite definitions and does not silently swallow schema failures.
- v21 repairs the missing MySQL `notifications` table before creating its indexes.
- v21 uses `clicked_at` for `clicks_log`.
- v21 checks table/column/index existence before creating indexes, making upgrades safe against already-partially-migrated databases.
- The standalone v21 SQL is provided separately for SQLite and MySQL.

## Validation

- PHP syntax check: passed for all PHP files.
- Wave P static regression gate: passed.
- Wave Q operational regression gate: passed.
- Wave W scale/concurrency gate: 11/11 passed.
