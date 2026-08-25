# Wave T — Subscription Payment Settlement

## Scope

This wave establishes the provider-neutral settlement ledger for online subscription payments. It is deliberately separate from provider-specific request/verify adapters.

## Implemented

- `payment_orders`: immutable server-side quote/order ledger.
- `payment_events`: unique provider-event ledger for callback replay protection.
- Per-user idempotency key uniqueness.
- Provider + provider-reference uniqueness.
- Server-controlled callback/return URL.
- Server-side price quote via `PaymentPricing`.
- MySQL row locking (`FOR UPDATE`) on the payment order.
- Exactly-once subscription activation inside one DB transaction.
- Existing active subscription is expired and the new subscription is inserted atomically.
- Referral first-purchase reward is inside the same transaction; failure rolls the settlement back.
- Provider amount mismatch is rejected and audited.
- Expired orders are rejected and audited.
- Duplicate provider references are rejected and audited.
- Repeated successful callbacks return the already-settled result instead of creating another subscription.
- Mobile/Web/PWA/Android share the same settlement backend boundary.

## Security boundary

`GatewayPaymentSettlement::settle()` accepts only an **already provider-verified** result. A provider adapter must perform the provider's remote verification/signature checks first. A browser callback must never be allowed to claim `verified=true` directly.

The current provider registry remains fail-closed for providers whose official adapter is not yet implemented. Therefore this wave does not pretend that live Zibal/ZarinPal money flow is production-ready merely because the settlement core exists.

## Validation

- PHP syntax: PASS for all application PHP files.
- Wave T static invariant gate: PASS 19/19.
- Database runtime integration: NOT CLAIMED in this environment because the available PHP build exposes PDO but no SQLite/MySQL driver. A real MySQL staging test remains mandatory before production activation.

## Explicit non-goals

- No activation based on client-supplied `paid`, amount, authority, or reference.
- No direct provider callback endpoint is exposed until the corresponding adapter verifies the provider response server-to-server.
- No secrets are embedded in this wave.
