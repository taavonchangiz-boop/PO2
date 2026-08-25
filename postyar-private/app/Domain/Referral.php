<?php
namespace WHCM\Domain;

use WHCM\Core\Bootstrap;

/**
 * مدیریت سیستم زیرمجموعه‌گیری (Referral System)
 *
 * شامل تولید کد معرف، پردازش ثبت‌نام با کد معرف، پاداش خرید اول
 * و مدیریت تنظیمات ادمین.
 *
 * @package WHCM\Domain
 */
class Referral {

    /**
     * تولید کد معرف یکتای ۱۰ کاراکتری (مثل POST-A3X7K9)
     *
     * @return string
     */
    public static function generateCode(): string {
        $db = Bootstrap::getDB();
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max_attempts = 50;

        for ($i = 0; $i < $max_attempts; $i++) {
            $random = '';
            for ($j = 0; $j < 6; $j++) {
                $random .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $code = 'POST-' . $random;

            // بررسی یکتایی
            $stmt = $db->prepare("SELECT 1 FROM users WHERE referral_code = ? LIMIT 1");
            $stmt->execute([$code]);
            if (!$stmt->fetch()) {
                return $code;
            }
        }

        // در صورت شکست، کد با timestamp یکتا
        return 'POST-' . strtoupper(substr(md5((string)time() . random_int(1000, 9999)), 0, 6));
    }

    /**
     * دریافت لینک زیرمجموعه‌گیری کامل کاربر
     *
     * @param int $userId
     * @return string
     */
    public static function getReferralLink(int $userId): string {
        $code = self::getUserReferralCode($userId);
        $baseUrl = rtrim(Bootstrap::getConfig('app.url'), '/');
        return $baseUrl . '/?ref=' . $code;
    }

    /**
     * دریافت کد معرف کاربر (تولید در صورت نبود)
     *
     * @param int $userId
     * @return string
     */
    public static function getUserReferralCode(int $userId): string {
        $db = Bootstrap::getDB();

        $stmt = $db->prepare("SELECT referral_code FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if (!empty($row['referral_code'])) {
            return $row['referral_code'];
        }

        // تولید و ذخیره کد جدید
        $code = self::generateCode();
        $stmt = $db->prepare("UPDATE users SET referral_code = ? WHERE id = ?");
        $stmt->execute([$code, $userId]);
        return $code;
    }

    /**
     * پردازش زیرمجموعه‌گیری هنگام ثبت‌نام
     *
     * @param int $newUserId شناسه کاربر جدید
     * @param string|null $referralCode کد معرف دریافتی از ?ref=
     * @return void
     */
    public static function processRegistration(int $newUserId, ?string $referralCode): void {
        if (empty($referralCode)) return;

        $db = Bootstrap::getDB();
        $settings = self::getAdminSettings();
        if (($settings['enabled'] ?? '0') !== '1') return;

        $stmt = $db->prepare("SELECT id FROM users WHERE referral_code = ? LIMIT 1");
        $stmt->execute([$referralCode]);
        $referrer = $stmt->fetch();
        if (!$referrer) return;
        $referrer_id = (int)$referrer['id'];
        if ($referrer_id === $newUserId) return;

        $max_referrals = max(0, (int)($settings['max_referrals_per_user'] ?? 100));
        $reward_type = $settings['register_reward_type'] ?? 'points';
        $reward_value = max(0, (float)($settings['register_reward_value'] ?? 100));

        $db->beginTransaction();
        try {
            // MySQL row lock serializes quota checks for the same referrer.
            $driver = Bootstrap::getConfig('database.driver', 'sqlite');
            $lockSql = $driver === 'mysql'
                ? "SELECT id FROM users WHERE id = ? LIMIT 1 FOR UPDATE"
                : "SELECT id FROM users WHERE id = ? LIMIT 1";
            $stmt = $db->prepare($lockSql);
            $stmt->execute([$referrer_id]);
            if (!$stmt->fetch()) throw new \RuntimeException('معرف معتبر نیست.');

            $stmt = $db->prepare("SELECT COUNT(*) FROM referrals WHERE referrer_id = ?");
            $stmt->execute([$referrer_id]);
            if ((int)$stmt->fetchColumn() >= $max_referrals) {
                $db->rollBack();
                return;
            }

            // referred_id UNIQUE is the second concurrency gate.
            $stmt = $db->prepare("UPDATE users SET referred_by = ? WHERE id = ? AND referred_by IS NULL");
            $stmt->execute([$referrer_id, $newUserId]);
            if ($stmt->rowCount() !== 1) {
                $db->rollBack();
                return;
            }

            $stmt = $db->prepare("INSERT INTO referrals (referrer_id, referred_id, referral_code, reward_type, reward_value, status, created_at) VALUES (?, ?, ?, ?, ?, 'pending', ?)");
            $stmt->execute([$referrer_id, $newUserId, $referralCode, $reward_type, $reward_value, date('Y-m-d H:i:s')]);
            self::awardReward($referrer_id, $newUserId, $reward_type, $reward_value, 'register');
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[Postyar] Referral registration failed: ' . $e->getMessage());
        }
    }

    /**
     * پردازش پاداش خرید اول (فراخوانی پس از تأیید پرداخت)
     *
     * @param int $userId شناسه خریدار (زیرمجموعه)
     * @param int $planId شناسه پلن
     * @param float $amount مبلغ خرید
     * @return void
     */
    public static function processFirstPurchase(int $userId, int $planId, float $amount): void {
        $db = Bootstrap::getDB();

        $settings = self::getAdminSettings();
        if (empty($settings['enabled']) || $settings['enabled'] !== '1') {
            return;
        }

        // یافتن رکورد زیرمجموعه فعال این کاربر
        $stmt = $db->prepare("SELECT * FROM referrals WHERE referred_id = ? AND status = 'pending' LIMIT 1");
        $stmt->execute([$userId]);
        $referral = $stmt->fetch();

        if (!$referral) {
            return;
        }

        $referrer_id = (int)$referral['referrer_id'];
        $ref_id = (int)$referral['id'];

        $reward_type = $settings['first_purchase_reward_type'] ?? 'percent';
        $reward_value = (float)($settings['first_purchase_reward_value'] ?? 10);

        // محاسبه مبلغ پاداش
        if ($reward_type === 'percent') {
            $reward_amount = round(($amount * $reward_value) / 100, 2);
        } else {
            $reward_amount = $reward_value;
        }

        // Claim اتمیک: فقط اولین درخواست مجاز است رکورد pending را به rewarded تبدیل کند.
        // این شرط جلوی پرداخت دوباره پاداش در دو درخواست همزمان را می‌گیرد.
        $stmt = $db->prepare(
            "UPDATE referrals
             SET status = 'rewarded', reward_type = ?, reward_value = ?, rewarded_at = ?
             WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([$reward_type, $reward_amount, date('Y-m-d H:i:s'), $ref_id]);
        if ($stmt->rowCount() !== 1) {
            return;
        }

        // واریز پاداش به کیف پول معرف
        $credited = Wallet::credit($referrer_id, $reward_amount, 'referral_purchase',
            'پاداش خرید اول زیرمجموعه (شناسه کاربر: ' . $userId . ')',
            'referral', $ref_id);
        if (!$credited) {
            throw new \RuntimeException('ثبت پاداش کیف پول معرف ناموفق بود.');
        }
    }

    /**
     * دریافت آمار زیرمجموعه‌گیری کاربر
     *
     * @param int $userId
     * @return array
     */
    public static function getReferralStats(int $userId): array {
        $db = Bootstrap::getDB();

        $stmt = $db->prepare("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'rewarded' THEN 1 ELSE 0 END) as rewarded,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
            FROM referrals WHERE referrer_id = ?
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        return [
            'total'    => (int)($row['total'] ?? 0),
            'rewarded' => (int)($row['rewarded'] ?? 0),
            'pending'  => (int)($row['pending'] ?? 0),
        ];
    }

    /**
     * دریافت تاریخچه زیرمجموعه‌های کاربر
     *
     * @param int $userId
     * @return array
     */
    public static function getReferralHistory(int $userId): array {
        $db = Bootstrap::getDB();

        $stmt = $db->prepare("
            SELECT r.*, u.name as referred_name, u.email as referred_email
            FROM referrals r
            LEFT JOIN users u ON r.referred_id = u.id
            WHERE r.referrer_id = ?
            ORDER BY r.created_at DESC
            LIMIT 100
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /**
     * دریافت تنظیمات ادمین سیستم زیرمجموعه‌گیری
     *
     * @return array
     */
    public static function getAdminSettings(): array {
        $db = Bootstrap::getDB();

        try {
            $stmt = $db->query("SELECT setting_key, setting_value FROM referral_settings");
            $rows = $stmt->fetchAll();
        } catch (\Exception $e) {
            return [];
        }

        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        return $settings;
    }

    /**
     * ذخیره تنظیمات ادمین سیستم زیرمجموعه‌گیری
     *
     * @param array $settings آرایه کلید => مقدار
     * @return void
     */
    public static function saveAdminSettings(array $settings): void {
        $db = Bootstrap::getDB();

        // دریافت کلیدهای موجود
        try {
            $stmt = $db->query("SELECT setting_key FROM referral_settings");
            $existing = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Exception $e) {
            $existing = [];
        }

        foreach ($settings as $key => $value) {
            if (in_array($key, $existing, true)) {
                $stmt = $db->prepare("UPDATE referral_settings SET setting_value = ? WHERE setting_key = ?");
            } else {
                $stmt = $db->prepare("INSERT INTO referral_settings (setting_key, setting_value) VALUES (?, ?)");
            }
            $stmt->execute([$value, $key]);
        }
    }

    /**
     * اعطای پاداش به معرف (ثبت‌نام)
     *
     * @param int $referrerId
     * @param int $referredId
     * @param string $rewardType نوع پاداش: points, days, percent
     * @param float $rewardValue مقدار پاداش
     * @param string $context نوع عملیات
     * @return void
     */
    private static function awardReward(int $referrerId, int $referredId, string $rewardType, float $rewardValue, string $context): void {
        $db = Bootstrap::getDB();

        if ($rewardType === 'points') {
            // اضافه کردن امتیاز به معرف
            $stmt = $db->prepare("UPDATE users SET referral_points = referral_points + ? WHERE id = ?");
            $stmt->execute([$rewardValue, $referrerId]);
            if ($stmt->rowCount() !== 1) throw new \RuntimeException('ثبت پاداش امتیازی ناموفق بود.');
        } elseif ($rewardType === 'days') {
            // تمدید اشتراک معرف
            $days = (int)$rewardValue;
            $stmt = $db->prepare("
                UPDATE subscriptions SET end_date = datetime(end_date, '+{$days} days')
                WHERE user_id = ? AND status = 'active'
                ORDER BY id DESC LIMIT 1
            ");
            try {
                $driver = Bootstrap::getConfig('database.driver', 'sqlite');
                if ($driver === 'mysql') {
                    $stmt = $db->prepare("
                        UPDATE subscriptions SET end_date = DATE_ADD(end_date, INTERVAL ? DAY)
                        WHERE user_id = ? AND status = 'active'
                        ORDER BY id DESC LIMIT 1
                    ");
                } else {
                    $stmt = $db->prepare("
                        UPDATE subscriptions SET end_date = datetime(end_date, '+' || ? || ' days')
                        WHERE user_id = ? AND status = 'active'
                        ORDER BY id DESC LIMIT 1
                    ");
                }
                $stmt->execute([$days, $referrerId]);
            } catch (\Exception $e) {}
        }
        // percent: در ثبت‌نام اعمال نمی‌شود، فقط در خرید اول
    }
}
