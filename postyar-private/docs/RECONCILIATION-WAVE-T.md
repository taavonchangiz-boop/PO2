# Wave T Artifact Reconciliation

Date: 2026-08-18

## Finding

The prior Wave T report was broader than the actual artifact. The supplied Wave T archive **does contain** the Wave T settlement implementation, but it does **not** contain real Zibal or ZarinPal provider adapters. Those providers remain fail-closed through `ConfiguredGateway`.

## Canonical decision

The canonical baseline is **R3 + the verified Wave T delta**.

Base:
- `Postyar-Wave-R3-Provider-Hardened/postyar-current`

Applied Wave T files:
- `app/Domain/PaymentOrder.php`
- `app/Domain/GatewayPaymentSettlement.php`
- `migrations/v26_payment_settlement.sql`
- `migrations/v26_payment_settlement_mysql.sql`
- `tests/wave_t_settlement_static.php`
- `docs/WAVE-T-PAYMENT-SETTLEMENT.md`
- `app/Api/Controllers/BillingApiController.php`
- `app/Api/Routes/api.php`
- `app/Core/Bootstrap.php`

## Verification

R3 and T differ in exactly 3 pre-existing common files, plus 6 T-only files. The 3 common-file changes are the expected Wave T integration points:
- Billing API online-order endpoint
- online-payment API route
- v26 migration registration

T static gate: `PASS 19/19`.

All PHP files in the T artifact pass `php -l`.

## Important non-claim

No real provider adapter is present for Zibal or ZarinPal in this artifact. `ConfiguredGateway` intentionally refuses live money flow until a provider-specific adapter is implemented and verified against the official provider contract.

Therefore:
- Wave T settlement core: **present**
- Zibal production integration: **NOT PRESENT / NOT VERIFIED**
- ZarinPal production integration: **NOT PRESENT / NOT VERIFIED**

This distinction is mandatory for the final score.
