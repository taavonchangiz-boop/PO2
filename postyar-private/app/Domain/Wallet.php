<?php
namespace WHCM\Domain;

use WHCM\Core\Bootstrap;

/**
 * مدیریت کیف پول و تراکنش‌های مالی کاربران
 *
 * شامل موجودی، واریز، برداشت، بازگشت وجه (کش‌بک)
 * و تبدیل امتیاز به موجودی کیف پول.
 *
 * @package WHCM\Domain
 */
class Wallet {

    /**
     * دریافت موجودی کیف پول کاربر
     *
     * @param int $userId
     * @return float
     */
    public static function getBalance(int $userId): float {
        $db = Bootstrap::getDB();

        try {
            $stmt = $db->prepare("SELECT wallet_balance FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            return (float)($row['wallet_balance'] ?? 0);
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    /**
     * واریز موجودی به کیف پول
     *
     * @param int $userId شناسه کاربر
     * @param float $amount مبلغ (مثبت)
     * @param string $type نوع تراکنش
     * @param string $description توضیحات
     * @param string|null $refType نوع مرجع
     * @param int|null $refId شناسه مرجع
     * @return bool
     */
    public static function credit(int $userId, float $amount, string $type, string $description, ?string $refType = null, ?int $refId = null): bool {
        if ($amount <= 0) {
            return false;
        }

        $db = Bootstrap::getDB();

        $ownsTransaction = false;
        try {
            if (!$db->inTransaction()) {
                $db->beginTransaction();
                $ownsTransaction = true;
            }

            // بروزرسانی موجودی
            $stmt = $db->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?");
            $stmt->execute([$amount, $userId]);

            // دریافت موجودی جدید
            $stmt = $db->prepare("SELECT wallet_balance FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $balanceAfter = (float)$stmt->fetch()['wallet_balance'];

            // ثبت تراکنش
            $stmt = $db->prepare("
                INSERT INTO wallet_transactions (user_id, type, amount, balance_after, description, reference_type, reference_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $type, $amount, $balanceAfter, $description, $refType, $refId, date('Y-m-d H:i:s')]);

            if ($ownsTransaction) $db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
            error_log('[Postyar] wallet credit failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * برداشت از کیف پول
     *
     * @param int $userId
     * @param float $amount مبلغ (مثبت)
     * @param string $description
     * @return bool false در صورت موجودی ناکافی
     */
    public static function debit(int $userId, float $amount, string $description): bool {
        if ($amount <= 0) {
            return false;
        }

        $db = Bootstrap::getDB();

        $ownsTransaction = false;
        try {
            if (!$db->inTransaction()) {
                $db->beginTransaction();
                $ownsTransaction = true;
            }

            // کسر اتمیک موجودی: شرط موجودی داخل UPDATE است تا دو درخواست همزمان
            // نتوانند هر دو یک موجودی قدیمی را معتبر فرض کنند.
            $stmt = $db->prepare(
                "UPDATE users
                 SET wallet_balance = wallet_balance - ?
                 WHERE id = ? AND wallet_balance >= ?"
            );
            $stmt->execute([$amount, $userId, $amount]);

            if ($stmt->rowCount() !== 1) {
                if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
                return false;
            }

            // خواندن موجودی پس از UPDATE برای ثبت ledger دقیق
            $stmt = $db->prepare("SELECT wallet_balance FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new \RuntimeException('کاربر کیف پول یافت نشد.');
            }
            $balanceAfter = (float)$row['wallet_balance'];

            // ثبت تراکنش
            $stmt = $db->prepare("
                INSERT INTO wallet_transactions (user_id, type, amount, balance_after, description, created_at)
                VALUES (?, 'debit', ?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $amount, $balanceAfter, $description, date('Y-m-d H:i:s')]);

            if ($ownsTransaction) $db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
            error_log('[Postyar] wallet debit failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * دریافت لیست تراکنش‌های کیف پول کاربر
     *
     * @param int $userId
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public static function getTransactions(int $userId, int $limit = 50, int $offset = 0): array {
        $db = Bootstrap::getDB();

        $stmt = $db->prepare("
            SELECT * FROM wallet_transactions
            WHERE user_id = ?
            ORDER BY id DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$userId, $limit, $offset]);
        return $stmt->fetchAll();
    }

    /**
     * کش‌بک (بازگشت وجه) هنگام خرید
     *
     * @param int $userId شناسه کاربر خریدار
     * @param float $purchaseAmount مبلغ خرید
     * @param float $percent درصد کش‌بک
     * @return bool
     */
    public static function cashbackOnPurchase(int $userId, float $purchaseAmount, float $percent): bool {
        if ($percent <= 0 || $purchaseAmount <= 0) {
            return false;
        }

        $cashbackAmount = round(($purchaseAmount * $percent) / 100, 2);

        return self::credit(
            $userId,
            $cashbackAmount,
            'cashback',
            'کش‌بک خرید — ' . $percent . '% از مبلغ ' . $purchaseAmount . ' تومان',
            'purchase',
            null
        );
    }

    /**
     * تبدیل امتیاز به موجودی کیف پول
     *
     * @param int $userId
     * @param float $points تعداد امتیاز
     * @param float $rate نرخ تبدیل (هر امتیاز = چند تومان)
     * @return bool
     */
    public static function convertPointsToWallet(int $userId, float $points, float $rate): bool {
        if ($points <= 0 || $rate <= 0) {
            return false;
        }

        $db = Bootstrap::getDB();

        try {
            $db->beginTransaction();

            // کسر اتمیک امتیاز: شرط کافی بودن امتیاز داخل UPDATE قرار دارد.
            $stmt = $db->prepare(
                "UPDATE users
                 SET referral_points = referral_points - ?
                 WHERE id = ? AND referral_points >= ?"
            );
            $stmt->execute([$points, $userId, $points]);

            if ($stmt->rowCount() !== 1) {
                $db->rollBack();
                return false;
            }

            $walletAmount = round($points * $rate, 2);

            // واریز به کیف پول در همان تراکنش
            $stmt = $db->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?");
            $stmt->execute([$walletAmount, $userId]);
            if ($stmt->rowCount() !== 1) {
                throw new \RuntimeException('به‌روزرسانی کیف پول انجام نشد.');
            }

            // دریافت موجودی جدید کیف پول
            $stmt = $db->prepare("SELECT wallet_balance FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new \RuntimeException('کاربر کیف پول یافت نشد.');
            }
            $balanceAfter = (float)$row['wallet_balance'];

            // ثبت تراکنش کیف پول
            $stmt = $db->prepare("
                INSERT INTO wallet_transactions (user_id, type, amount, balance_after, description, reference_type, created_at)
                VALUES (?, 'points_convert', ?, ?, ?, 'points_conversion', ?)
            ");
            $stmt->execute([
                $userId,
                $walletAmount,
                $balanceAfter,
                'تبدیل ' . $points . ' امتیاز به موجودی کیف پول (نرخ: ' . $rate . ')',
                date('Y-m-d H:i:s')
            ]);

            $db->commit();
            return true;
        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return false;
        }
    }

    /**
     * دریافت آمار کلی کیف پول‌ها (برای پنل ادمین)
     *
     * @return array
     */
    public static function getAdminWalletStats(): array {
        $db = Bootstrap::getDB();

        try {
            $stmt = $db->query("SELECT COALESCE(SUM(wallet_balance), 0) as total_balance, COUNT(*) as total_users FROM users");
            $row = $stmt->fetch();

            $stmt = $db->query("SELECT COUNT(*) as total FROM wallet_transactions");
            $totalTransactions = (int)$stmt->fetch()['total'];

            $stmt = $db->query("SELECT COALESCE(SUM(wallet_balance), 0) as active_balance FROM users WHERE wallet_balance > 0");
            $activeBalance = (float)$stmt->fetch()['active_balance'];

            return [
                'total_balance'      => (float)$row['total_balance'],
                'total_users'        => (int)$row['total_users'],
                'total_transactions' => $totalTransactions,
                'active_balance'     => $activeBalance,
            ];
        } catch (\Exception $e) {
            return [
                'total_balance'      => 0,
                'total_users'        => 0,
                'total_transactions' => 0,
                'active_balance'     => 0,
            ];
        }
    }
}
