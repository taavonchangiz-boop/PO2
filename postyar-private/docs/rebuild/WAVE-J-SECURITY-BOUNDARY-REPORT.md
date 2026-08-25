# Wave J — Security Boundary Hardening Report

## Scope
Wave J closes remaining browser-side mutation and request-boundary weaknesses identified during Waves G–I. No business feature was removed; legacy URLs for mutation actions are now POST-only and the existing module handlers remain canonical.

## Changes

1. **Destructive admin GET routes removed**
   - `/hnnh/suspend-user`
   - `/hnnh/activate-user`
   - `/hnnh/delete-user`
   - `/hnnh/approve-payment`
   - `/hnnh/delete-plan`
   These operations are already implemented by CSRF-protected POST handlers and are now reachable only through those handlers.

2. **Admin UI converted from mutation links to POST forms**
   - User suspend/activate/delete
   - Payment approval
   - Plan deletion
   Each form carries the current CSRF token and the action-specific identifier in a hidden field.

3. **Manual administrator mutations hardened**
   - Manual user creation now validates CSRF.
   - Manual subscription grants now validate CSRF.

4. **Logout made POST-only + CSRF-protected**
   Browser logout is no longer a state-changing GET. Desktop and mobile dashboard/admin controls submit a POST form.

5. **CSRF rotation across authentication boundaries**
   The CSRF token is rotated after successful password/OTP login when the session ID is regenerated, reducing cross-boundary token reuse.

6. **Mobile API response hardening**
   API JSON responses now emit `no-store`, `nosniff`, and `no-referrer` headers. API requests above 2 MiB are rejected before JSON parsing.

7. **Regression coverage**
   Added `tests/wave_j_security_boundary_regression.sh`. Existing Wave G/H/I regressions were re-run; the Wave G static check was updated to reflect the current modular payment-controller architecture.

## Verification

- Wave G static regression: **PASS**
- Wave H anti-abuse/idempotency regression: **PASS**
- Wave I auth/OTP regression: **PASS**
- Wave J security-boundary regression: **PASS**
- All PHP files: **syntax PASS**
- SQLite runtime concurrency regression: **BLOCKED by environment** because this PHP runtime does not have the PDO SQLite driver enabled. This is an environment limitation, not a claimed application failure.

## Explicit non-claims

Wave J does not claim production load capacity of 5,000–10,000 concurrent users. That requires a real PHP + database + web-server environment and a controlled load test. Static correctness is not a substitute for load validation.

## Next wave recommendation

Wave K should focus on **data isolation and production-scale reliability**: tenant-bound authorization/IDOR audit across every web/API controller, database index/query audit, queue/cron locking, transaction boundaries, and deterministic load-test fixtures.
