<?php
namespace WHCM\Payments;

/**
 * Provider-neutral payment contract. A real bank gateway adapter must verify
 * provider signatures/server-side status before calling the settlement service.
 */
interface PaymentGatewayInterface
{
    public function createPayment(int $orderId, int $userId, float $amount, string $returnUrl): array;
    public function verifyCallback(array $payload): array;
}
