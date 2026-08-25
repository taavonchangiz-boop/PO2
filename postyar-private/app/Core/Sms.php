<?php
namespace WHCM\Core;

use WHCM\Core\Bootstrap;
use WHCM\Core\HttpClient;

/**
 * کلاس ارسال پیامک از طریق SMS.ir
 *
 * =============================================================
 * نکته یکپارچه‌سازی:
 *   - تایید پرداخت → در Wallet.php یا PaymentController
 *     پس از تایید تراکنش، متد Sms::send() با event_key='payment_confirm' فراخوانی شود.
 *   - ثبت‌نام کاربر → در MainController::handleRegister()
 *     پس از ایجاد حساب، Sms::send() با event_key='registration' فراخوانی شود.
 *   - بازنشانی رمز عبور → در MainController::handleResetPasswordConfirm()
 *     پس از تغییر رمز، Sms::send() با event_key='password_reset' فراخوانی شود.
 *   - یادآوری انقضای اشتراک → در Cron Job
 *     Sms::send() با event_key='subscription_expiry' فراخوانی شود.
 * =============================================================
 *
 * @package WHCM.Core
 */
class Sms {

    /** آدرس پایه API */
    private const API_URL = 'https://sms.ir/users/send/ultrafast';

    /** حداکثر پیامک در ساعت برای هر شماره */
    private const RATE_LIMIT_PER_HOUR = 3;

    /**
     * ارسال پیامک تکی به یک شماره
     *
     * @param string $phone شماره موبایل (09xx)
     * @param int $templateId شناسه قالب SMS.ir
     * @param array $parameters پارامترهای قالب  [ ['Parameter' => 'name', 'ParameterValue' => 'علی'], ... ]
     * @param int|null $userId شناسه کاربر (اختیاری، برای لاگ)
     * @return array ['success' => bool, 'message_id' => ?, 'error' => ?]
     */
    public static function send(string $phone, int $templateId, array $parameters = [], ?int $userId = null): array {
        // Provider selection is centralized. Non-SMS.ir providers are fail-closed until their reviewed adapter is configured.
        $activeProvider = self::activeProvider();
        if ($activeProvider !== 'smsir') {
            $phone = self::normalizePhone($phone) ?? '';
            if ($phone === '') return ['success'=>false,'message_id'=>null,'error'=>'شماره موبایل نامعتبر است.'];
            $result = SmsProviderRegistry::provider($activeProvider)->sendPattern($phone, (string)$templateId, $parameters);
            return ['success'=>(bool)($result['success']??false), 'message_id'=>$result['message_id']??null, 'error'=>$result['error']??null];
        }
        // ۱. بررسی فعال بودن سرویس
        if (!self::isEnabled()) {
            return ['success' => false, 'message_id' => null, 'error' => 'سرویس پیامک غیرفعال است.'];
        }

        // ۲. اعتبارسنجی شماره موبایل
        $phone = self::normalizePhone($phone);
        if ($phone === null) {
            return ['success' => false, 'message_id' => null, 'error' => 'شماره موبایل نامعتبر است. فرمت صحیح: 09xxxxxxxxx'];
        }

        // ۳. بررسی Rate Limit
        $rateCheck = self::checkRateLimit($phone);
        if (!$rateCheck['allowed']) {
            self::logSent($templateId, $phone, $userId, 'rate_limited', null, $rateCheck['reason']);
            return ['success' => false, 'message_id' => null, 'error' => $rateCheck['reason']];
        }

        // ۴. دریافت API Key
        $apiKey = self::getApiKey();
        if (empty($apiKey)) {
            self::logSent($templateId, $phone, $userId, 'failed', null, 'API Key پیامک تنظیم نشده است.');
            return ['success' => false, 'message_id' => null, 'error' => 'API Key پیامک تنظیم نشده است.'];
        }

        // ۵. آماده‌سازی پارامترها
        $params = [];
        foreach ($parameters as $key => $value) {
            $params[] = ['Parameter' => $key, 'ParameterValue' => (string)$value];
        }

        // ۶. ارسال درخواست به API
        $body = [
            'Mobile'     => $phone,
            'TemplateId' => $templateId,
            'Parameters' => $params,
        ];

        $response = HttpClient::post(self::API_URL, $body, [
            'X-API-KEY: ' . $apiKey,
            'Content-Type: application/json',
        ], 15);

        // ۷. پردازش پاسخ
        if (!$response['success']) {
            $errorMsg = $response['error'] ?: 'خطا در ارتباط با سرور SMS.ir';
            self::logSent($templateId, $phone, $userId, 'failed', (string)$response['code'], $errorMsg);
            return ['success' => false, 'message_id' => null, 'error' => $errorMsg];
        }

        $data = json_decode($response['body'], true);

        if (!$data) {
            $errorMsg = 'پاسخ نامعتبر از سرور SMS.ir';
            self::logSent($templateId, $phone, $userId, 'failed', null, $errorMsg);
            return ['success' => false, 'message_id' => null, 'error' => $errorMsg];
        }

        if (!empty($data['isSuccessful'])) {
            $messageId = $data['messageId'] ?? null;
            self::logSent($templateId, $phone, $userId, 'success', (string)($messageId ?? ''), null);
            return ['success' => true, 'message_id' => $messageId, 'error' => null];
        }

        $errorMsg = $data['message'] ?? 'خطای ناشناخته در ارسال پیامک';
        self::logSent($templateId, $phone, $userId, 'failed', null, $errorMsg);
        return ['success' => false, 'message_id' => null, 'error' => $errorMsg];
    }

    /**
     * ارسال پیامک انبوه به چندین شماره
     *
     * @param array $phones آرایه‌ای از شماره‌های موبایل
     * @param int $templateId شناسه قالب
     * @param array $parameters پارامترهای قالب (یکسان برای همه)
     * @return array ['success' => bool, 'sent_count' => int, 'failed_count' => int, 'errors' => []]
     */
    public static function sendBulk(array $phones, int $templateId, array $parameters = []): array {
        if (!self::isEnabled()) {
            return ['success' => false, 'sent_count' => 0, 'failed_count' => count($phones), 'errors' => ['سرویس پیامک غیرفعال است.']];
        }

        $apiKey = self::getApiKey();
        if (empty($apiKey)) {
            return ['success' => false, 'sent_count' => 0, 'failed_count' => count($phones), 'errors' => ['API Key پیامک تنظیم نشده است.']];
        }

        // نرمال‌سازی و فیلتر شماره‌ها
        $validPhones = [];
        $errors = [];
        foreach ($phones as $phone) {
            $normalized = self::normalizePhone($phone);
            if ($normalized !== null) {
                $validPhones[] = $normalized;
            } else {
                $errors[] = 'شماره ' . htmlspecialchars($phone) . ' نامعتبر است.';
            }
        }

        if (empty($validPhones)) {
            return ['success' => false, 'sent_count' => 0, 'failed_count' => count($phones), 'errors' => $errors];
        }

        // آماده‌سازی پارامترها
        $params = [];
        foreach ($parameters as $key => $value) {
            $params[] = ['Parameter' => $key, 'ParameterValue' => (string)$value];
        }

        $body = [
            'Mobiles'    => $validPhones,
            'TemplateId' => $templateId,
            'Parameters' => $params,
        ];

        $response = HttpClient::post(self::API_URL, $body, [
            'X-API-KEY: ' . $apiKey,
            'Content-Type: application/json',
        ], 30);

        if (!$response['success']) {
            $errorMsg = $response['error'] ?: 'خطا در ارتباط با سرور SMS.ir';
            $errors[] = $errorMsg;
            // لاگ به صورت تکی
            foreach ($validPhones as $phone) {
                self::logSent($templateId, $phone, null, 'failed', (string)$response['code'], $errorMsg);
            }
            return ['success' => false, 'sent_count' => 0, 'failed_count' => count($phones), 'errors' => $errors];
        }

        $data = json_decode($response['body'], true);

        if (!$data) {
            $errorMsg = 'پاسخ نامعتبر از سرور SMS.ir';
            $errors[] = $errorMsg;
            foreach ($validPhones as $phone) {
                self::logSent($templateId, $phone, null, 'failed', null, $errorMsg);
            }
            return ['success' => false, 'sent_count' => 0, 'failed_count' => count($phones), 'errors' => $errors];
        }

        if (!empty($data['isSuccessful'])) {
            $messageId = $data['messageId'] ?? null;
            foreach ($validPhones as $phone) {
                self::logSent($templateId, $phone, null, 'success', (string)($messageId ?? ''), null);
            }
            return ['success' => true, 'sent_count' => count($validPhones), 'failed_count' => count($phones) - count($validPhones), 'errors' => $errors];
        }

        $errorMsg = $data['message'] ?? 'خطای ناشناخته در ارسال پیامک انبوه';
        $errors[] = $errorMsg;
        foreach ($validPhones as $phone) {
            self::logSent($templateId, $phone, null, 'failed', null, $errorMsg);
        }
        return ['success' => false, 'sent_count' => 0, 'failed_count' => count($phones), 'errors' => $errors];
    }

    /**
     * تست اتصال — ارسال پیامک تستی به شماره ادمین یا بررسی تنظیمات
     *
     * @return array نتیجه تست
     */
    public static function testConnection(?string $phone = null): array {
        // بررسی اولیه تنظیمات
        $apiKey = self::getApiKey();
        if (empty($apiKey)) {
            return ['success' => false, 'message' => 'API Key تنظیم نشده است. لطفاً ابتدا کلید API را وارد کنید.'];
        }

        if (!self::isEnabled()) {
            return ['success' => false, 'message' => 'سرویس پیامک غیرفعال است. ابتدا آن را فعال کنید.'];
        }

        // اگر شماره‌ای داده شده، پیامک تستی بفرست
        if ($phone !== null) {
            $phone = self::normalizePhone($phone);
            if ($phone === null) {
                return ['success' => false, 'message' => 'شماره موبایل وارد شده نامعتبر است.'];
            }

            // بررسی وجود حداقل یک قالب فعال
            $db = Bootstrap::getDB();
            $stmt = $db->prepare("SELECT template_id FROM sms_templates WHERE is_active = 1 LIMIT 1");
            $stmt->execute();
            $template = $stmt->fetch();

            if (!$template) {
                return ['success' => false, 'message' => 'هیچ قالب پیامک فعالی وجود ندارد. لطفاً ابتدا یک قالب تعریف کنید.'];
            }

            $result = self::send($phone, (int)$template['template_id'], []);
            if ($result['success']) {
                return ['success' => true, 'message' => 'پیامک تستی با موفقیت ارسال شد! (شناسه: ' . $result['message_id'] . ')'];
            } else {
                return ['success' => false, 'message' => 'خطا در ارسال پیامک تستی: ' . ($result['error'] ?? 'نامشخص')];
            }
        }

        // اگر شماره‌ای نداد، فقط تنظیمات را بررسی کن
        $lineNumber = self::getLineNumber();
        return [
            'success' => true,
            'message' => 'تنظیمات API Key معتبر است. شماره خط: ' . ($lineNumber ?: 'تنظیم نشده'),
        ];
    }

    // =================================================================
    // متدهای کمکی خصوصی
    // =================================================================

    /**
     * نرمال‌سازی شماره موبایل
     * @return string|null شماره نرمال‌سازی شده یا null اگر نامعتبر باشد
     */
    private static function normalizePhone(string $phone): ?string {
        $phone = preg_replace('/[\s\-\(\)\+]/', '', $phone);

        // اگر با 98 شروع شد، به 0 تبدیل کن
        if (strlen($phone) === 12 && strpos($phone, '98') === 0) {
            $phone = '0' . substr($phone, 2);
        }

        // اعتبارسنجی: دقیقاً ۱۱ رقم و شروع با 09
        if (preg_match('/^09\d{9}$/', $phone)) {
            return $phone;
        }

        return null;
    }

    /**
     * بررسی Rate Limit برای یک شماره
     * @return array ['allowed' => bool, 'reason' => ?string]
     */
    private static function checkRateLimit(string $phone): array {
        $db = Bootstrap::getDB();
        $driver = Bootstrap::getConfig('database.driver', 'sqlite');

        if ($driver === 'mysql') {
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM sms_log 
                WHERE phone = ? AND status IN ('success', 'rate_limited')
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
        } else {
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM sms_log 
                WHERE phone = ? AND status IN ('success', 'rate_limited')
                  AND created_at >= datetime('now', '-1 hour')
            ");
        }
        $stmt->execute([$phone]);
        $count = (int)$stmt->fetchColumn();

        if ($count >= self::RATE_LIMIT_PER_HOUR) {
            return [
                'allowed' => false,
                'reason' => 'حداکثر ' . self::RATE_LIMIT_PER_HOUR . ' پیامک در ساعت برای این شماره مجاز است.',
            ];
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * ثبت لاگ ارسال پیامک
     */
    private static function logSent(int $templateId, string $phone, ?int $userId, string $status, ?string $responseCode, ?string $errorMessage): void {
        try {
            $db = Bootstrap::getDB();
            $stmt = $db->prepare("
                INSERT INTO sms_log (template_id, phone, user_id, status, response_code, error_message)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$templateId, $phone, $userId, $status, $responseCode, $errorMessage]);
        } catch (\Exception $e) {
            // خطای لاگ نباید عملیات اصلی را مختل کند
        }
    }

    /**
     * بررسی فعال بودن سرویس پیامک
     */
    private static function activeProvider(): string {
        try {
            $db=Bootstrap::getDB();
            $stmt=$db->query("SELECT key_value FROM settings WHERE tenant_id=0 AND key_name='sms_provider_active' LIMIT 1");
            $v=(string)($stmt->fetchColumn() ?: 'smsir');
            return SmsProviderRegistry::get($v) ? $v : 'smsir';
        } catch (\Throwable $e) { return 'smsir'; }
    }

    private static function isEnabled(): bool {
        // اولویت: بررسی تنظیمات ذخیره شده در دیتابیس
        $db = Bootstrap::getDB();
        try {
            $stmt = $db->prepare("SELECT key_value FROM settings WHERE tenant_id = 0 AND key_name = ? LIMIT 1");
            $stmt->execute(['sms_enabled']);
            $row = $stmt->fetch();
            if ($row !== false) {
                return ($row['key_value'] ?? '') === '1';
            }
        } catch (\Exception $e) {}

        // فالبک: بررسی فایل کانفیگ
        return (bool)Bootstrap::getConfig('sms.enabled', false);
    }

    /**
     * دریافت API Key — اولویت با تنظیمات دیتابیس
     */
    private static function getApiKey(): string {
        $db = Bootstrap::getDB();
        try {
            $stmt = $db->prepare("SELECT key_value FROM settings WHERE tenant_id = 0 AND key_name = ? LIMIT 1");
            $stmt->execute(['sms_api_key']);
            $row = $stmt->fetch();
            if ($row !== false && !empty($row['key_value'])) {
                return SecretStore::decrypt((string)$row['key_value']);
            }
        } catch (\Exception $e) {}

        return Bootstrap::getConfig('sms.api_key', '');
    }

    /**
     * دریافت شماره خط — اولویت با تنظیمات دیتابیس
     */
    private static function getLineNumber(): string {
        $db = Bootstrap::getDB();
        try {
            $stmt = $db->prepare("SELECT key_value FROM settings WHERE tenant_id = 0 AND key_name = ? LIMIT 1");
            $stmt->execute(['sms_line_number']);
            $row = $stmt->fetch();
            if ($row !== false && !empty($row['key_value'])) {
                return $row['key_value'];
            }
        } catch (\Exception $e) {}

        return Bootstrap::getConfig('sms.line_number', '');
    }
}
