# Payment Gateway Boundary

Advertising card-to-card payments are deliberately kept separate from the existing subscription `payments` table.

When a bank gateway is purchased, implement one adapter behind `PaymentGatewayInterface` and route both subscription and advertising online payments through a provider-neutral payment-attempt/order layer.

Required gateway invariants:

1. Never trust client-side `paid=true`, amount, authority, or reference.
2. Verify callback/signature/server status with the provider.
3. Compare provider amount with the immutable server quote.
4. Use an idempotency/unique provider transaction key.
5. Settle exactly once inside a DB transaction.
6. Never activate an ad before atomic settlement succeeds.
7. Persist provider response only after redacting secrets/tokens.
8. Web/PWA/Android all use the same backend settlement path.
