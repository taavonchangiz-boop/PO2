<?php
namespace WHCM\Domain;

use WHCM\Core\Bootstrap;
use WHCM\Core\SecretStore;
use WHCM\Core\HttpClient;

/**
 * مدیریت صندوق پیام‌ها (Inbox) و سیستم پاسخگوی خودکار مبتنی بر کلمات کلیدی (Auto-responder)
 *
 * @package WHCM\Domain
 */
class Inbox {

    /**
     * پردازش پیام‌های دریافتی از طریق وبهوک (Webhook)
     */
    public static function handleWebhook(array $channel) {
        $input = file_get_contents('php://input');
        $update = json_decode((string)$input, true);
        if (is_array($update)) {
            self::handleUpdateArray($channel, $update);
        }
    }

    /**
     * پردازش آرایه به‌روزرسانی (ارسالی از وبهوک یا دریافتی از Polling)
     */
    public static function handleUpdateArray(array $channel, array $update) {
        $msg = null;
        // بررسی کلیدهای مختلف ساختار پیام در بله و تلگرام
        foreach (['message', 'edited_message', 'channel_post', 'edited_channel_post'] as $key) {
            if (!empty($update[$key]) && is_array($update[$key])) {
                $msg = $update[$key];
                break;
            }
        }

        if (!$msg || empty($msg['text'])) {
            return;
        }

        $sender_id = (string)($msg['from']['id'] ?? ($msg['sender_chat']['id'] ?? ($msg['chat']['id'] ?? '')));
        // پاکسازی متون دریافتی جهت امنیت بالا
        $sender_name = htmlspecialchars(trim($msg['from']['first_name'] ?? ($msg['sender_chat']['title'] ?? ($msg['chat']['title'] ?? ''))), ENT_QUOTES, 'UTF-8');
        $message_text = trim($msg['text']);
        // هنگام مقایسه کلمه کلیدی، نباید htmlspecialchars شده باشد

        if (empty($sender_id) || empty($message_text)) {
            return;
        }

        self::receiveMessage($channel, $sender_id, $sender_name, $message_text);
    }

    /**
     * دریافت پیام، ثبت در دیتابیس و پردازش کلمات کلیدی جهت پاسخ خودکار
     */
    public static function receiveMessage(array $channel, string $sender_id, string $sender_name, string $message_text) {
        $db = Bootstrap::getDB();
        $tenant_id = (int)$channel['tenant_id'];
        $reply_text = '';
        $matched_keyword = '';
        $reply_sent = 0;

        // ۰. بررسی اینکه آیا پاسخگوی خودکار برای این کانال فعال است یا خیر
        $stmt_check = $db->prepare("SELECT key_value FROM settings WHERE tenant_id = ? AND key_name = ? LIMIT 1");
        $stmt_check->execute([$tenant_id, 'responder_enabled_' . (int)$channel['id']]);
        $enabled_row = $stmt_check->fetch();
        $responder_enabled = $enabled_row && (int)$enabled_row['key_value'] === 1;

        // ۱. بررسی کلمات کلیدی فعال برای این کانال و مستاجر (فقط اگر فعال باشد)
        if ($responder_enabled) {
            $stmt = $db->prepare("SELECT * FROM auto_replies WHERE tenant_id = ? AND channel_id = ? AND active = 1");
            $stmt->execute([$tenant_id, $channel['id']]);
            $auto_replies = $stmt->fetchAll();

            foreach ($auto_replies as $rule) {
                $keyword = trim($rule['keyword']);
                
                // مقایسه هوشمند غیرحساس به حروف بزرگ و کوچک (فارسی/انگلیسی)
                $found = function_exists('mb_stripos')
                    ? mb_stripos($message_text, $keyword) !== false
                    : stripos($message_text, $keyword) !== false;

                if ($found) {
                    $reply_text = $rule['reply_text'];
                    $matched_keyword = $keyword;
                    // ارسال زنده پاسخ خودکار به کاربر پیام‌رسان
                    $sent = self::sendReplyToUser($channel, $sender_id, $reply_text);
                    $reply_sent = $sent ? 1 : 0;
                    break;
                }
            }
        }

        // ۱.۵. ثبت در لاگ پاسخگوی خودکار
        try {
            $safe_text = mb_substr($message_text, 0, 500);
            $stmt_log = $db->prepare("INSERT INTO responder_logs (tenant_id, channel_id, sender_id, sender_name, message_text, matched_keyword, reply_sent) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt_log->execute([$tenant_id, $channel['id'], $sender_id, $sender_name, $safe_text, $matched_keyword, $reply_sent]);
        } catch (\Throwable $e) {}

        // ۲. ثبت نهایی پیام در صندوق پیام‌های مستأجر
        $safe_message = $message_text . (!empty($reply_text) ? "\n\n[پاسخ خودکار ارسال شد: {$reply_text}]" : '');
        $stmt = $db->prepare("
            INSERT INTO inbox (tenant_id, channel_id, sender_id, sender_name, message_text) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $tenant_id,
            $channel['id'],
            $sender_id,
            $sender_name,
            $safe_message
        ]);
    }

    /**
     * ارسال مستقیم پاسخ به آیدی کاربر در تلگرام/بله
     */
    public static function sendReplyToUser(array $channel, string $user_id, string $text): bool {
        $platform = $channel['platform'] ?? 'telegram';
        $token = trim($channel['token'] ?? '');
        if (empty($token)) {
            return false;
        }

        $endpoint = Sender::apiBase($platform) . $token . '/sendMessage';
        $body = ['chat_id' => $user_id, 'text' => $text];

        $res = HttpClient::post($endpoint, $body, [], 15);
        return $res['success'];
    }

    /**
     * فرآیند Polling برای یک کانال مشخص (جهت پاسخگو بدون نیاز به وبهوک و HTTPS)
     */
    public static function pollChannelUpdates(array $channel) {
        $token = trim($channel['token'] ?? '');
        if (empty($token)) {
            return;
        }

        $db = Bootstrap::getDB();
        $tenant_id = (int)$channel['tenant_id'];

        // الف) بازیابی آخرین آفست ذخیره‌شده برای این کانال در تنظیمات مستاجر
        $setting_key = "upd_offset_" . (int)$channel['id'];
        $stmt = $db->prepare("SELECT key_value FROM settings WHERE tenant_id = ? AND key_name = ? LIMIT 1");
        $stmt->execute([$tenant_id, $setting_key]);
        $last_offset_row = $stmt->fetch();
        $last_offset = $last_offset_row ? (int)$last_offset_row['key_value'] : 0;

        $endpoint = Sender::apiBase($channel['platform']) . $token . '/getUpdates';
        $body = [
            'offset' => $last_offset + 1,
            'limit' => 50,
            'timeout' => 2
        ];

        $res = HttpClient::post($endpoint, $body, [], 15);
        if (!$res['success']) {
            return;
        }

        $data = json_decode($res['body'], true);
        if (!is_array($data) || empty($data['ok'])) {
            // در صورت بروز تداخل وبهوک (کد ۴۰۹)، وضعیت وبهوک را در دیتابیس همگام می‌کنیم
            if (isset($data['error_code']) && (int)$data['error_code'] === 409) {
                $db->prepare("UPDATE channels SET webhook_active = 1 WHERE id = ?")->execute([$channel['id']]);
            }
            return;
        }

        $new_offset = $last_offset;
        foreach ($data['result'] as $update) {
            if (!is_array($update)) {
                continue;
            }
            $upd_id = (int)($update['update_id'] ?? 0);
            if ($upd_id > $new_offset) {
                $new_offset = $upd_id;
            }
            self::handleUpdateArray($channel, $update);
        }

        // ب) ذخیره آفست جدید (سازگار با تمامی دیتابیس‌ها)
        $stmt = $db->prepare("SELECT id FROM settings WHERE tenant_id = ? AND key_name = ? LIMIT 1");
        $stmt->execute([$tenant_id, $setting_key]);
        if ($stmt->fetch()) {
            $stmt = $db->prepare("UPDATE settings SET key_value = ? WHERE tenant_id = ? AND key_name = ?");
            $stmt->execute([$new_offset, $tenant_id, $setting_key]);
        } else {
            $stmt = $db->prepare("INSERT INTO settings (tenant_id, key_name, key_value) VALUES (?, ?, ?)");
            $stmt->execute([$tenant_id, $setting_key, $new_offset]);
        }
    }

    /**
     * اجرای خودکار Polling برای تمامی کانال‌های فعال همه‌ی مستاجرینی که وبهوک فعال ندارند
     */
    public static function pollAllActive() {
        $db = Bootstrap::getDB();
        
        // فقط کانال‌هایی که از روش Polling استفاده می‌کنند و وبهوک فعال ندارند را بررسی می‌کنیم
        $stmt = $db->query("SELECT * FROM channels WHERE webhook_active = 0");
        $channels = $stmt->fetchAll();
        foreach ($channels as &$channel) { $channel['token'] = SecretStore::decrypt((string)$channel['token']); }
        unset($channel);

        foreach ($channels as $channel) {
            // بررسی دسترسی کاربر به صندوق پیام و پاسخگو از روی پلن اشتراک
            $quota = Quota::getTenantQuota((int)$channel['tenant_id']);
            if ($quota['has_active_sub'] && !empty($quota['features']['auto_responder'])) {
                self::pollChannelUpdates($channel);
            }
        }
    }
}
