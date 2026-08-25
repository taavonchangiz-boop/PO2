<?php
namespace WHCM\Domain;

use WHCM\Core\Bootstrap;
use WHCM\Core\EmailTemplate;
use WHCM\Core\Sms;

/**
 * مدیریت خودکار انقضای اشتراک‌ها توسط Cron Job
 *
 * - علامت‌گذاری اشتراک‌های منقضی‌شده
 * - ارسال یادآوری (ایمیل/پیامک) ۳ روز قبل از انقضا
 * - ارسال اطلاع‌رسانی پس از انقضا
 * - پاکسازی کدهای تایید منقضی‌شده
 *
 * @package WHCM\Domain
 */
class SubscriptionManager {

    /** 
     * تعداد روز قبل از انقضا که یادآوری ارسال می‌شود 
     */
    private const REMINDER_DAYS_BEFORE = 3;

    /**
     * پردازش تمامی اشتراک‌ها:
     *   ۱. انقضای اشتراک‌های گذشته
     *   ۲. ارسال یادآوری ۳ روز قبل از انقضا
     *
     * @return array ['expired' => int, 'reminded' => int]
     */
    public static function processExpiries(): array {
        $db = Bootstrap::getDB();
        $now = date('Y-m-d H:i:s');
        $expired_count = 0;
        $reminded_count = 0;
        $driver = Bootstrap::getConfig('database.driver', 'sqlite');

        // ---- ۱. علامت‌گذاری اشتراک‌های منقضی‌شده ----
        if ($driver === 'mysql') {
            $stmt = $db->query("
                SELECT s.id, s.user_id, s.plan_id, s.end_date, p.title as plan_title
                FROM subscriptions s
                JOIN plans p ON s.plan_id = p.id
                WHERE s.status = 'active' AND s.end_date <= NOW()
            ");
        } else {
            $stmt = $db->prepare("
                SELECT s.id, s.user_id, s.plan_id, s.end_date, p.title as plan_title
                FROM subscriptions s
                JOIN plans p ON s.plan_id = p.id
                WHERE s.status = 'active' AND s.end_date <= ?
            ");
            $stmt->execute([$now]);
        }

        $expired_subs = $stmt->fetchAll();

        foreach ($expired_subs as $sub) {
            $sub_id = (int)$sub['id'];
            $user_id = (int)$sub['user_id'];
            $plan_title = $sub['plan_title'] ?? 'نامشخص';

            // تغییر وضعیت به منقضی
            $db->prepare("UPDATE subscriptions SET status = 'expired' WHERE id = ?")->execute([$sub_id]);
            $expired_count++;

            // ارسال اطلاع‌رسانی انقضا (ایمیل)
            try {
                EmailTemplate::sendByEvent('subscription_expired', $user_id, [
                    'plan_name' => $plan_title,
                ]);
            } catch (\Throwable $e) {
                error_log('[Postyar Cron] Expiry email failed for user #' . $user_id . ': ' . $e->getMessage());
            }

            // ارسال اطلاع‌رسانی انقضا (پیامک)
            try {
                self::sendSmsByEvent('subscription_expired', $user_id, [
                    'plan_name' => $plan_title,
                ]);
            } catch (\Throwable $e) {
                error_log('[Postyar Cron] Expiry SMS failed for user #' . $user_id . ': ' . $e->getMessage());
            }
        }

        // ---- ۲. ارسال یادآوری ۳ روز قبل از انقضا ----
        $reminder_threshold = date('Y-m-d H:i:s', strtotime('+' . self::REMINDER_DAYS_BEFORE . ' days'));

        if ($driver === 'mysql') {
            $stmt = $db->query("
                SELECT s.id, s.user_id, s.plan_id, s.end_date, p.title as plan_title,
                       DATEDIFF(s.end_date, NOW()) as days_left
                FROM subscriptions s
                JOIN plans p ON s.plan_id = p.id
                WHERE s.status = 'active'
                  AND s.end_date > NOW()
                  AND DATEDIFF(s.end_date, NOW()) <= " . self::REMINDER_DAYS_BEFORE . "
                  AND (s.expiry_reminder_sent IS NULL OR s.expiry_reminder_sent = 0)
            ");
        } else {
            $stmt = $db->prepare("
                SELECT s.id, s.user_id, s.plan_id, s.end_date, p.title as plan_title,
                       CAST((julianday(s.end_date) - julianday(?)) AS INTEGER) as days_left
                FROM subscriptions s
                JOIN plans p ON s.plan_id = p.id
                WHERE s.status = 'active'
                  AND s.end_date <= ?
                  AND s.end_date > ?
                  AND (s.expiry_reminder_sent IS NULL OR s.expiry_reminder_sent = 0)
            ");
            $stmt->execute([$now, $reminder_threshold, $now]);
        }

        $reminder_subs = $stmt->fetchAll();

        foreach ($reminder_subs as $sub) {
            $sub_id = (int)$sub['id'];
            $user_id = (int)$sub['user_id'];
            $plan_title = $sub['plan_title'] ?? 'نامشخص';
            $days_left = max(1, (int)($sub['days_left'] ?? 1));

            // علامت‌گذاری یادآوری ارسال‌شده
            try { $db->prepare("UPDATE subscriptions SET expiry_reminder_sent = 1 WHERE id = ?")->execute([$sub_id]); } catch (\Exception $e) {}

            // ارسال یادآوری (ایمیل)
            try {
                EmailTemplate::sendByEvent('subscription_expiry', $user_id, [
                    'plan_name' => $plan_title,
                    'days_left' => $days_left,
                ]);
            } catch (\Throwable $e) {
                error_log('[Postyar Cron] Reminder email failed for user #' . $user_id . ': ' . $e->getMessage());
            }

            // ارسال یادآوری (پیامک)
            try {
                self::sendSmsByEvent('subscription_expiry', $user_id, [
                    'plan_name' => $plan_title,
                    'days_left' => $days_left,
                ]);
            } catch (\Throwable $e) {
                error_log('[Postyar Cron] Reminder SMS failed for user #' . $user_id . ': ' . $e->getMessage());
            }

            $reminded_count++;
        }

        return [
            'expired' => $expired_count,
            'reminded' => $reminded_count,
        ];
    }

    /**
     * پاکسازی کدهای تایید منقضی‌شده و استفاده‌شده
     *
     * @return int تعداد حذف‌شده
     */
    public static function cleanupVerificationCodes(): int {
        $db = Bootstrap::getDB();
        $driver = Bootstrap::getConfig('database.driver', 'sqlite');

        try {
            if ($driver === 'mysql') {
                $stmt = $db->exec("
                    DELETE FROM verification_codes
                    WHERE used = 1
                       OR expires_at < NOW()
                ");
                return $stmt;
            } else {
                $stmt = $db->prepare("
                    DELETE FROM verification_codes
                    WHERE used = 1
                       OR expires_at < datetime('now')
                ");
                $stmt->execute();
                return $stmt->rowCount();
            }
        } catch (\Exception $e) {
            error_log('[Postyar Cron] Verification code cleanup error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * ارسال پیامک بر اساس event_key با دریافت template_id از دیتابیس
     */
    private static function sendSmsByEvent(string $eventKey, int $userId, array $variables = []): void {
        $db = Bootstrap::getDB();

        // دریافت template_id از جدول sms_templates
        $stmt = $db->prepare("SELECT template_id, parameters FROM sms_templates WHERE event_key = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$eventKey]);
        $tpl = $stmt->fetch();

        if (!$tpl || empty($tpl['template_id'])) {
            return;
        }

        $templateId = (int)$tpl['template_id'];

        // دریافت شماره موبایل کاربر
        $stmt = $db->prepare("SELECT phone FROM users WHERE id = ? AND phone IS NOT NULL AND phone != '' LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            return;
        }

        // تبدیل متغیرها به فرمت SMS.ir
        $params = [];
        foreach ($variables as $key => $value) {
            $params[] = ['Parameter' => $key, 'ParameterValue' => (string)$value];
        }

        Sms::send($user['phone'], $templateId, $params, $userId);
    }
}
