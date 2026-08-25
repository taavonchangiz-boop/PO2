# Wave I — Authentication, Phone OTP, Recovery & Session Hardening

## Scope

This wave hardens the complete authentication/recovery chain for Web and Android without trusting a six-digit OTP as a global identifier.

## Changes

- Web password login now validates the required phone binding in addition to email/password.
- Added Web phone-login request/verify flow with SMS OTP.
- Added Android/API phone-login request/verify flow with SMS OTP.
- OTP verification is scoped to the resolved user and is atomically consumed (`used=0` + expiry condition).
- Added IP + phone/user scoped OTP rate limits.
- Email and SMS password-reset requests are rate limited.
- SMS password-reset verification now requires the supplied phone and no longer searches all users by a six-digit code.
- Email reset tokens are stored as SHA-256 hashes at rest for newly issued tokens.
- Password changes/reset revoke all mobile API tokens.
- Session IDs are regenerated after authentication-sensitive transitions.
- Added unique normalized phone index and unique rate-limit bucket index through v15 migration.
- Added verification-code hot-path index.

## Important limitation

The current environment has PDO but no PDO SQLite/MySQL driver, so live DB concurrency tests cannot be honestly marked PASS. Static and syntax gates pass; production-like MySQL/InnoDB tests remain required before release.

## Remaining security decision

Registration currently records a phone number and enforces uniqueness/free-trial claim, but full pre-account phone ownership verification (OTP before account activation) is intentionally not marked complete. This must be addressed before a security-certified production release.
