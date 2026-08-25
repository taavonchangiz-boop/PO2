# Wave R.2 — Advertising Sales & Payment Workflow

## Business state machine

`submitted -> awaiting_payment -> payment_submitted -> paid`

Terminal alternatives: `rejected`, `cancelled`, `expired`.

A campaign linked to an order can be publicly visible only when:

- campaign status = `approved`
- campaign payment_status = `paid`
- current time is inside the requested/approved window

## Request model

An advertiser may select one or more placement slots and submit up to 10 creatives/slides. A creative can contain title + text and/or image, plus one validated destination URL.

The administrator is the pricing authority. The client cannot choose the final price.

## Card-to-card now

1. User submits request.
2. Admin reviews content/placement/time.
3. Admin enters authoritative quoted amount.
4. User sees quote and payment instructions.
5. User submits reference + receipt.
6. Admin verifies receipt.
7. Payment/order/campaign activation transition is atomic.

## Future bank gateway

The system has a provider-neutral payment boundary. A gateway adapter must verify provider-side status and amount, enforce idempotency, and call the same settlement transition used by card-to-card verification.

No UI or API endpoint is allowed to activate an ad merely because a browser/app says payment succeeded.

## Scale/security

- placement/date overlap must be checked before activation in the next concurrency gate
- order ownership is always checked server-side
- quote is authoritative server-side
- receipt is never public
- payment approval is idempotent
- campaign visibility remains payment-gated
- Android/PWA/Web use the same domain rules and API contract

## Payment reference anti-replay

Card-to-card references are unique per advertiser. A second advertising order cannot reuse the same reference for the same owner.
