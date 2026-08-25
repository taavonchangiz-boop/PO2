# Remediation status

This branch contains the first high-priority remediation pass derived from the security and UI/UX audit.

## Applied

- API middleware is fail-closed: unknown middleware names now block the request.
- API request bodies have a hard size limit and malformed JSON is rejected.
- API route parsing ignores query strings and handler errors no longer disclose implementation details.
- API responses receive baseline security headers.
- Public Apache configuration now disables directory listing, blocks common sensitive files, enables compression/cache headers, and adds defense-in-depth security headers.
- Dashboard responsive loading was reduced from a multi-version patch chain to one authoritative responsive baseline.
- New responsive baseline improves touch targets, focus visibility, reduced-motion behavior, mobile notification layout, table overflow, safe-area handling, and mobile typography.

## Intentionally not claimed complete

This branch is not certified as bug-free or production-approved. Full remediation still requires execution against the complete application and infrastructure, including:

- PHP syntax and static analysis across every file.
- Dependency and secret scanning.
- Auth/payment/provider integration tests.
- Browser/device regression testing on real iOS, Android, and tablet hardware.
- Database migration and constraint testing on every supported database engine.
- End-to-end tests for scheduled delivery, retries, idempotency, payment reconciliation, and wallet accounting.
- CSP migration away from `unsafe-inline`; current views contain inline handlers/styles and require a coordinated nonce/hash refactor before a strict CSP can be safely enabled.
- Full decomposition of the large `MainController.php`, `Bootstrap.php`, `dashboard.php`, and `admin.php` files.
- Exact-once/ambiguous external delivery semantics and distributed worker locking.

## Deployment notes

Test this branch in a staging environment before merging. Apache headers require `mod_headers`; compression and expiry directives are conditional on the relevant modules. HSTS should only be used when the site is fully HTTPS-enabled.
