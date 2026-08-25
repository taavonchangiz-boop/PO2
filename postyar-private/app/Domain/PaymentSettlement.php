<?php
namespace WHCM\Domain;

use WHCM\Core\Bootstrap;
use WHCM\Core\Transaction;

/**
 * Single authoritative settlement path for admin-approved payments.
 * Payment state, subscription state and first-purchase referral reward are committed atomically.
 */
class PaymentSettlement
{
    public static function approve(int $paymentId): array
    {
        if ($paymentId <= 0) throw new \InvalidArgumentException('شناسه پرداخت نامعتبر است.');

        return Transaction::run(function (\PDO $db) use ($paymentId): array {
            $driver = Bootstrap::getConfig('database.driver', 'sqlite');
            $paymentSql = 'SELECT * FROM payments WHERE id = ? LIMIT 1';
            if ($driver === 'mysql') $paymentSql .= ' FOR UPDATE';
            $stmt = $db->prepare($paymentSql);
            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch();
            if (!$payment) throw new \RuntimeException('پرداخت مورد نظر یافت نشد.');
            if (($payment['status'] ?? '') !== 'pending') {
                throw new \RuntimeException('این پرداخت قبلاً پردازش شده است.');
            }

            $userId = (int)$payment['user_id'];
            $planId = (int)$payment['plan_id'];
            $userSql = 'SELECT id FROM users WHERE id = ? LIMIT 1';
            if ($driver === 'mysql') $userSql .= ' FOR UPDATE';
            $stmt = $db->prepare($userSql);
            $stmt->execute([$userId]);
            if (!$stmt->fetch()) throw new \RuntimeException('کاربر پرداخت یافت نشد.');

            $stmt = $db->prepare('SELECT * FROM plans WHERE id = ? LIMIT 1');
            $stmt->execute([$planId]);
            $plan = $stmt->fetch();
            if (!$plan) throw new \RuntimeException('پلن مربوط به پرداخت یافت نشد.');

            // Recalculate the authoritative price. Legacy/client-manipulated amounts are never trusted.
            $base = max(0.0, (float)$plan['price']);
            $general = max(0, min(100, (int)($plan['general_discount'] ?? 0)));
            $expected = $base * (1 - ($general / 100));
            $stmt = $db->prepare("SELECT end_date FROM subscriptions WHERE user_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$userId]);
            $active = $stmt->fetch();
            if ($active && strtotime((string)$active['end_date']) > time()) {
                $early = max(0, min(100, (int)($plan['early_renewal_discount'] ?? 0)));
                if ($early > 0) $expected *= (1 - ($early / 100));
            }
            $expected = round(max(0.0, $expected), 2);
            $paid = round((float)$payment['amount'], 2);
            $snapshot = isset($payment['quoted_amount']) && $payment['quoted_amount'] !== null
                ? round((float)$payment['quoted_amount'], 2)
                : $expected;
            if (abs($paid - $snapshot) > 0.01) {
                throw new \RuntimeException('مبلغ پرداخت با مبلغ ثبت‌شده در زمان سفارش مطابقت ندارد؛ تایید متوقف شد.');
            }

            $now = date('Y-m-d H:i:s');
            $stmt = $db->prepare("UPDATE payments SET status = 'approved', verified_at = ? WHERE id = ? AND status = 'pending'");
            $stmt->execute([$now, $paymentId]);
            if ($stmt->rowCount() !== 1) throw new \RuntimeException('پرداخت همزمان توسط درخواست دیگری پردازش شد.');

            $stmt = $db->prepare("UPDATE subscriptions SET status = 'expired' WHERE user_id = ? AND status = 'active'");
            $stmt->execute([$userId]);

            $duration = (int)$plan['duration_days'];
            $endDate = $duration > 0 ? date('Y-m-d H:i:s', strtotime("+{$duration} days")) : '2099-12-30 00:00:00';
            $stmt = $db->prepare("INSERT INTO subscriptions (user_id, plan_id, start_date, end_date, status) VALUES (?, ?, ?, ?, 'active')");
            $stmt->execute([$userId, $planId, $now, $endDate]);
            $subscriptionId = (int)$db->lastInsertId();

            // First-purchase referral reward is part of the same DB transaction.
            try {
                Referral::processFirstPurchase($userId, $planId, $paid);
            } catch (\Throwable $e) {
                throw new \RuntimeException('تسویه پاداش زیرمجموعه انجام نشد و کل تایید پرداخت rollback شد.', 0, $e);
            }

            return [
                'payment_id' => $paymentId,
                'subscription_id' => $subscriptionId,
                'user_id' => $userId,
                'plan_id' => $planId,
                'plan_title' => (string)$plan['title'],
                'amount' => $paid,
            ];
        });
    }
}
