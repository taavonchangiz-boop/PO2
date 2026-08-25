# Wave I Regression Report

- PHP syntax across application/public tree: PASS
- Wave G static regression: PASS
- Wave H anti-abuse/idempotency regression: PASS
- Wave I auth/OTP regression: PASS
- Dynamic SQLite/MySQL concurrency: BLOCKED by missing PDO driver

## Current route counts

- Web: 25 GET + 68 POST = 93 registered routes in `public/index.php`.
- Mobile API: 23 GET + 32 POST + 4 PUT + 4 DELETE = 63 registered routes.

These counts include the new phone-login endpoints and must supersede older wave-local route counts.
