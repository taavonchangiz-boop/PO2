<?php
namespace WHCM\Domain;

use WHCM\Core\Bootstrap;

/**
 * Server-authoritative pricing for subscription purchases.
 * Never trusts a client supplied amount.
 */
class PaymentPricing
{
    public static function quote(int $userId, int $planId): array
    {
        if ($userId <= 0 || $planId <= 0) {
            throw new \InvalidArgumentException('شناسه کاربر یا پلن نامعتبر است.');
        }
        $db = Bootstrap::getDB();
        $stmt = $db->prepare('SELECT * FROM plans WHERE id = ? LIMIT 1');
        $stmt->execute([$planId]);
        $plan = $stmt->fetch();
        if (!$plan) throw new \RuntimeException('پلن مورد نظر یافت نشد.');

        $base = max(0.0, (float)$plan['price']);
        $general = max(0, min(100, (int)($plan['general_discount'] ?? 0)));
        $price = $base * (1 - ($general / 100));

        $earlyEligible = false;
        $stmt = $db->prepare("SELECT end_date FROM subscriptions WHERE user_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$userId]);
        $sub = $stmt->fetch();
        if ($sub && strtotime((string)$sub['end_date']) > time()) {
            $early = max(0, min(100, (int)($plan['early_renewal_discount'] ?? 0)));
            if ($early > 0) {
                $earlyEligible = true;
                $price *= (1 - ($early / 100));
            }
        }

        // Currency is stored in decimal columns. Round once, server-side.
        $final = round(max(0.0, $price), 2);
        return [
            'plan' => $plan,
            'amount' => $final,
            'base_amount' => round($base, 2),
            'general_discount_percent' => $general,
            'early_renewal_eligible' => $earlyEligible,
            'early_renewal_discount_percent' => $earlyEligible ? max(0, min(100, (int)($plan['early_renewal_discount'] ?? 0))) : 0,
        ];
    }
}
