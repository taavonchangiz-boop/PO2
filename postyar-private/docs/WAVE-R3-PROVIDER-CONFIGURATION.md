# Wave R.3 — Payment / SMS / SMTP Provider Configuration

## Scope

This wave adds a centralized administration boundary for payment gateways, SMS providers and SMTP settings, while keeping all credentials server-side.

### Payment providers catalogued
- Intermediaries: Zarinpal, IDPay, NextPay, Pay.ir, Aqayepardakht
- Direct bank: Mellat, Saman, Pasargad, Tejarat, Saderat
- Custom provider

### SMS providers catalogued
- SMS.ir
- Kavenegar
- MeliPayamak
- FarazSMS
- Custom provider

## Security invariants

1. Provider selection does not equal provider activation.
2. Changing credentials resets provider verification to `0` (fail-closed).
3. Secret fields are encrypted using `SecretStore` and `POSTYAR_SECRET_KEY` (or `security.secret_key`).
4. Secret values are not rendered back into admin forms.
5. Payment callbacks must never be trusted as proof of settlement.
6. Provider-specific wire formats are not guessed. Named adapters refuse to initiate/verify money until their official API contract is explicitly implemented and verified.
7. Web/PWA/Android must use the same server-side settlement path.

## SMTP

Admin configuration now includes:
- enabled
- host
- port
- username
- encrypted password
- encryption mode
- SMTP AUTH toggle
- timeout
- From address/name
- Reply-To address/name

Database settings override config-file defaults. SMTP password is never displayed back to the administrator.

## Important implementation boundary

The provider catalog and admin configuration are production-safe infrastructure, but **a named bank/gateway is not claimed to be live merely because it appears in the UI**. Before production money movement, each selected provider requires a reviewed adapter using its current official merchant API, server-side amount verification, callback/signature verification and idempotent settlement.
