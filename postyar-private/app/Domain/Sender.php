<?php
namespace WHCM\Domain;

use WHCM\Core\Bootstrap;
use WHCM\Core\SecretStore;
use WHCM\Core\HttpClient;

/**
 * مسئول ارسال پیام‌ها و فایل‌های چندرسانه‌ای به شبکه‌های اجتماعی (تلگرام و بله)
 *
 * @package WHCM\Domain
 */
class Sender {

    /**
     * بازیابی پایه آدرس API ربات‌ها
     */
    public static function apiBase(string $platform): string {
        if ($platform === 'bale') {
            return 'https://tapi.bale.ai/bot';
        }
        return 'https://api.telegram.org/bot';
    }

    /**
     * تولید متن نهایی کپشن پست (متن ساده + دکمه‌های شیشه‌ای برای پیشگیری از نمایش خام تگ‌ها در بله)
     *
     * @param string $title عنوان پیام
     * @param string $content محتوای پیام
     * @param array $channel آرایه اطلاعات کانال
     * @param int $post_id شناسه پست جهت ایجاد لینک ردیابی کلیک
     * @return string
     */
    public static function formatCaption(string $title, string $content, array $channel, int $post_id = 0): string {
        $db = Bootstrap::getDB();
        
        // دریافت تنظیمات کپشن (html یا plain) از دیتابیس مستاجر
        $stmt = $db->prepare("SELECT key_value FROM settings WHERE tenant_id = ? AND key_name = 'caption_format' LIMIT 1");
        $stmt->execute([$channel['tenant_id']]);
        $setting = $stmt->fetch();
        $format = $setting ? $setting['key_value'] : 'plain';

        $links = json_decode($channel['link_config'] ?? '[]', true);
        if (empty($links)) {
            $links = [
                ['name' => 'مشاهده وب‌سایت', 'url' => ''],
                ['name' => 'کانال تلگرام', 'url' => ''],
                ['name' => 'کانال بله', 'url' => '']
            ];
        }

        // جایگزینی لینک اختصاصی ردیاب کلیک به جای لینک سوم (طبق منطق سورس اصلی)
        if ($post_id > 0) {
            $app_url = Bootstrap::getConfig('app.url', 'http://localhost');
            $links[2]['url'] = rtrim($app_url, '/') . "/click?p={$post_id}&c={$channel['id']}";
        }

        if ($format === 'html') {
            $text  = "🌟 <b>" . htmlspecialchars($title) . "</b> 🌟\n\n";
            $text .= trim($content) . "\n";
            $text .= "—————————————\n";
            foreach ($links as $l) {
                if (!empty($l['name']) && !empty($l['url'])) {
                    $text .= "<a href=\"" . htmlspecialchars($l['url']) . "\">" . htmlspecialchars($l['name']) . "</a>\n";
                }
            }
            return trim($text);
        }

        // حالت پیش‌فرض: متن ساده، بدون تگ HTML (سازگاری ۱۰۰٪ با بله)
        $text  = "🌟 {$title} 🌟\n\n";
        $text .= trim($content) . "\n";
        $text .= "—————————————\n";
        foreach ($links as $l) {
            if (!empty($l['name']) && !empty($l['url'])) {
                $text .= "◾️ {$l['name']}\n";
            }
        }

        return trim($text);
    }

    /**
     * تولید آرایه دکمه‌های شیشه‌ای تعاملی (Inline Keyboards)
     */
    public static function getInlineKeyboards(array $channel, int $post_id = 0): ?string {
        $btn_cfg = json_decode($channel['button_config'] ?? '{}', true);
        if (empty($btn_cfg) || empty($btn_cfg['active'])) {
            return null;
        }

        $links = json_decode($channel['link_config'] ?? '[]', true);
        if (empty($links)) {
            $links = [
                ['name' => 'مشاهده وب‌سایت', 'url' => ''],
                ['name' => 'کانال تلگرام', 'url' => ''],
                ['name' => 'کانال بله', 'url' => '']
            ];
        }

        if ($post_id > 0) {
            $app_url = Bootstrap::getConfig('app.url', 'http://localhost');
            $links[2]['url'] = rtrim($app_url, '/') . "/click?p={$post_id}&c={$channel['id']}";
        }

        $btns = $btn_cfg['buttons'] ?? [];

        $rowA = [];
        foreach ($links as $l) {
            if (!empty($l['name']) && !empty($l['url'])) {
                $rowA[] = ['text' => $l['name'], 'url' => $l['url']];
            }
        }

        $rowB = [];
        foreach ($btns as $b) {
            if (!empty($b['text']) && !empty($b['url'])) {
                $rowB[] = ['text' => $b['text'], 'url' => $b['url']];
            }
        }

        $keyboard = ['inline_keyboard' => []];
        if (!empty($rowA)) {
            $keyboard['inline_keyboard'][] = $rowA;
        }
        if (!empty($rowB)) {
            $keyboard['inline_keyboard'][] = $rowB;
        }

        if (empty($keyboard['inline_keyboard'])) {
            return null;
        }

        return json_encode($keyboard, JSON_UNESCAPED_UNICODE);
    }

    /**
     * ارسال یک پست به کانالی خاص
     */
    public static function sendPostToChannel(array $channel, string $title, string $content, string $media_url = '', int $post_id = 0): array {
        $platform = $channel['platform'] ?? 'telegram';
        $token = trim($channel['token'] ?? '');
        $chat_id = trim($channel['channel_id'] ?? '');

        if (empty($token) || empty($chat_id)) {
            return ['success' => false, 'message' => "توکن یا شناسه کانال «{$channel['name']}» تنظیم نشده است."];
        }

        $is_video = false;
        if (!empty($media_url)) {
            $ext = strtolower(pathinfo(parse_url($media_url, PHP_URL_PATH), PATHINFO_EXTENSION));
            if (in_array($ext, ['mp4', 'mov', 'webm'])) {
                $is_video = true;
            }
            // واترمرک در فازهای بعدی در صورت فعال‌سازی اضافه می‌شود
        }

        $caption = self::formatCaption($title, $content, $channel, $post_id);
        $api_url = self::apiBase($platform) . $token;
        $reply_markup = self::getInlineKeyboards($channel, $post_id);

        if (!empty($media_url) && $is_video) {
            $endpoint = $api_url . '/sendVideo';
            $body = ['chat_id' => $chat_id, 'video' => $media_url, 'caption' => $caption];
        } elseif (!empty($media_url)) {
            $endpoint = $api_url . '/sendPhoto';
            $body = ['chat_id' => $chat_id, 'photo' => $media_url, 'caption' => $caption];
        } else {
            $endpoint = $api_url . '/sendMessage';
            $body = ['chat_id' => $chat_id, 'text' => $caption, 'disable_web_page_preview' => false];
        }

        if ($reply_markup) {
            $body['reply_markup'] = $reply_markup;
        }

        // ارسال درخواست HTTP از طریق HttpClient همه‌منظوره پلتفرم
        $response = HttpClient::post($endpoint, $body, [], 30);

        if (!$response['success']) {
            return [
                'success' => false,
                'message' => !empty($response['error']) ? $response['error'] : 'خطای ارتباط با سرور پیام‌رسان.'
            ];
        }

        $data = json_decode($response['body'], true);
        if (!empty($data['ok'])) {
            $msg_id = $data['result']['message_id'] ?? '';
            return ['success' => true, 'message_id' => strval($msg_id)];
        }

        return [
            'success' => false,
            'message' => $data['description'] ?? 'خطا در ارسال پیام به شبکه اجتماعی.'
        ];
    }

    /**
     * ارسال گروهی پست به چندین کانال هدف مستاجر و ذخیره‌سازی آمار
     */
    public static function sendPostToChannels(int $tenant_id, array $channel_ids, string $title, string $content, string $media_url = '', int $post_id = 0): array {
        $results = [];
        $overall_success = true;
        $db = Bootstrap::getDB();

        foreach ($channel_ids as $cid) {
            // دریافت اطلاعات کانال با رعایت احراز هویت مستاجر
            $stmt = $db->prepare("SELECT * FROM channels WHERE id = ? AND tenant_id = ? LIMIT 1");
            $stmt->execute([$cid, $tenant_id]);
            $channel = $stmt->fetch();

            if (!$channel) {
                continue;
            }

            // A worker retry must not send a channel twice when the previous attempt
            // already reached the provider and only the final DB status update raced.
            if ($post_id > 0) {
                $sentStmt = $db->prepare("SELECT message_id FROM channel_messages WHERE post_id = ? AND channel_id = ? AND status = 'sent' LIMIT 1");
                $sentStmt->execute([$post_id, (int)$channel['id']]);
                $alreadySent = $sentStmt->fetch();
                if ($alreadySent) {
                    $results[] = [
                        'channel_id' => (int)$channel['id'],
                        'name' => $channel['name'],
                        'success' => true,
                        'message' => 'already_sent',
                        'message_id' => (string)($alreadySent['message_id'] ?? '')
                    ];
                    continue;
                }
            }

            $res = self::sendPostToChannel($channel, $title, $content, $media_url, $post_id);

            // در صورتی که شناسه پست معتبر باشد، جزئیات ارسال و آمار را ذخیره می‌کنیم
            if ($post_id > 0) {
                $status = $res['success'] ? 'sent' : 'failed';
                $msg_id = $res['message_id'] ?? '';

                // One logical delivery record per post/channel. The unique key added
                // in Wave L turns retries into an update instead of duplicate ledger rows.
                $driver = Bootstrap::getConfig('database.driver', 'sqlite');
                if ($driver === 'mysql') {
                    $stmt = $db->prepare("INSERT INTO channel_messages (post_id, channel_id, message_id, status) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE message_id = VALUES(message_id), status = VALUES(status), sent_at = CURRENT_TIMESTAMP");
                } else {
                    $stmt = $db->prepare("INSERT INTO channel_messages (post_id, channel_id, message_id, status) VALUES (?, ?, ?, ?) ON CONFLICT(post_id, channel_id) DO UPDATE SET message_id = excluded.message_id, status = excluded.status, sent_at = CURRENT_TIMESTAMP");
                }
                $stmt->execute([$post_id, $channel['id'], $msg_id, $status]);

                // ثبت اولیه آمار کلیک و بازدید پست در این کانال (سازگار با تمامی دیتابیس‌ها)
                $stmt = $db->prepare("SELECT id FROM post_channel_stats WHERE post_id = ? AND channel_id = ? LIMIT 1");
                $stmt->execute([$post_id, $channel['id']]);
                if (!$stmt->fetch()) {
                    $stmt = $db->prepare("INSERT INTO post_channel_stats (post_id, channel_id, clicks, views) VALUES (?, ?, 0, 0)");
                    $stmt->execute([$post_id, $channel['id']]);
                }
            }

            $results[] = [
                'channel_id' => (int)$channel['id'],
                'name' => $channel['name'],
                'success' => $res['success'],
                'message' => $res['message'] ?? '',
                'message_id' => $res['message_id'] ?? ''
            ];

            if (!$res['success']) {
                $overall_success = false;
            }
        }

        return [
            'success' => $overall_success,
            'channels' => $results
        ];
    }

    /**
     * ویرایش زنده کپشن پیام ارسال شده
     */
    public static function liveUpdateCaption(array $channel, string $message_id, string $new_caption): bool {
        if (empty($message_id)) {
            return false;
        }

        $platform = $channel['platform'] ?? 'telegram';
        $token = trim($channel['token'] ?? '');
        if (empty($token)) {
            return false;
        }

        $endpoint = self::apiBase($platform) . $token . '/editMessageCaption';
        $body = [
            'chat_id' => trim($channel['channel_id'] ?? ''),
            'message_id' => $message_id,
            'caption' => $new_caption,
            'parse_mode' => 'HTML'
        ];

        $res = HttpClient::post($endpoint, $body, [], 15);
        return $res['success'] && !empty(json_decode($res['body'], true)['ok']);
    }
}
