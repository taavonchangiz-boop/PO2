# Postyar Hardened Release

## Applied
- Removed ticket-message stored-XSS sinks from dashboard/admin rendering by replacing untrusted `innerHTML` construction with DOM `textContent`.
- Encrypted Telegram/Bale channel bot tokens at rest with `SecretStore` (AES-256-GCM) and encrypted legacy plaintext values via migration `v29_secret_and_private_storage_hardening`.
- Removed channel bot tokens from mobile API responses.
- Encrypted tenant AI API keys and stopped returning them from settings API; the dashboard no longer renders stored AI secrets.
- Added SSRF protection for tenant-controlled gold API URLs: HTTPS only, public DNS/IP only, no credentials, no non-443 ports.
- Fixed Telegram webhook secret initialization so the generated secret is actually sent as `secret_token`.
- Moved ticket attachments to private storage, added MIME validation, randomized filenames, blocked legacy public ticket directory under Apache, and added authenticated attachment download API.
- Replaced security-sensitive `uniqid()` identifiers with `random_bytes()`-based identifiers.
- Added private storage configuration and a `.gitignore` for private files.
- Fixed an existing JavaScript syntax error in `public_html/service-worker.js`.
- Added migration support for legacy channel secrets, AI keys, and legacy public ticket attachments.

## Deployment requirement
Set a strong `POSTYAR_SECRET_KEY` environment variable before first application boot after deployment. Do not commit this secret to the repository.

The v29 migration intentionally fails closed if the encryption key is missing.

## Verification
- PHP syntax check: PASS for all PHP files.
- JavaScript syntax check: PASS for all JS files.
- Static security gates: PASS.
- Concurrency regression test could not execute in this build environment because the PHP SQLite PDO driver is unavailable; this is an environment limitation, not a reported application failure.
