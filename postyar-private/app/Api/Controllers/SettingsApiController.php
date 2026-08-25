<?php
namespace WHCM\Api\Controllers;

use WHCM\Api\MobileApiResponse;
use WHCM\Domain\GoldTicker;
use WHCM\Core\Bootstrap;
use WHCM\Core\SecretStore;

/**
 * کنترلر API تنظیمات کاربر
 *
 * شامل: دریافت تنظیمات، ذخیره تنظیمات طلا، انتشار دستی طلا،
 * ذخیره تنظیمات پیشرفته، مدیریت پاسخگو خودکار
 *
 * @package WHCM\Api\Controllers
 */
class SettingsApiController extends \WHCM\Api\MobileApiController {

    /**
     * دریافت تنظیمات کاربر
     * GET /api/v1/settings (auth)
     */
    public function getSettings(): void {
        $tenantId = $this->userId();
        $db = $this->db();

        $stmt = $db->prepare("SELECT key_name, key_value FROM settings WHERE tenant_id = ?");
        $stmt->execute([$tenantId]);
        $rows = $stmt->fetchAll();

        $settings = [];
        $secretKeys = ['ai_api_key'];
        foreach ($rows as $row) {
            $key = (string)$row['key_name'];
            $settings[$key] = in_array($key, $secretKeys, true)
                ? (!empty($row['key_value']) ? ['configured' => true] : ['configured' => false])
                : $row['key_value'];
        }

        MobileApiResponse::success($settings);
    }

    /**
     * ذخیره تنظیمات مربوط به طلا
     * POST /api/v1/settings/gold (auth)
     *
     * Input: gold_schedule, gold_api_url, gold_currency, gold_template, gold_channels (array), gold_image (file)
     */
    public function saveGoldSettings(): void {
        $tenantId = $this->userId();
        $db       = $this->db();
        $input    = $this->input();

        $goldSchedule  = trim($input['gold_schedule'] ?? '');
        $goldApiUrl    = trim($input['gold_api_url'] ?? '');
        $goldCurrency  = trim($input['gold_currency'] ?? '');
         if ($goldApiUrl !== '' && !\WHCM\Domain\GoldTicker::isAllowedApiUrl($goldApiUrl)) {
             MobileApiResponse::error('آدرس API طلا معتبر یا مجاز نیست.', 400);
         }
        $goldTemplate  = trim($input['gold_template'] ?? '');
        $goldChannels  = $input['gold_channels'] ?? [];

        // آپلود تصویر طلا در صورت وجود
        $goldImageUrl = null;
        if (isset($_FILES['gold_image']) && $_FILES['gold_image']['error'] === UPLOAD_ERR_OK) {
            $goldImageUrl = $this->uploadImage('gold_image', 'uploads');
        }

        $settingsMap = [
            'gold_schedule'   => $goldSchedule,
            'gold_api_url'    => $goldApiUrl,
            'gold_currency'   => $goldCurrency,
            'gold_template'   => $goldTemplate,
            'gold_auto_channels' => is_array($goldChannels) ? json_encode($goldChannels, JSON_UNESCAPED_UNICODE) : '',
        ];

        // اگر تصویر آپلود شده بود
        if ($goldImageUrl !== null) {
            $settingsMap['gold_image_url'] = $goldImageUrl;
        }

        // UPSERT هر تنظیم
        foreach ($settingsMap as $key => $value) {
            $stmt = $db->prepare("SELECT id FROM settings WHERE tenant_id = ? AND key_name = ? LIMIT 1");
            $stmt->execute([$tenantId, $key]);

            if ($stmt->fetch()) {
                $stmt = $db->prepare("UPDATE settings SET key_value = ? WHERE tenant_id = ? AND key_name = ?");
                $stmt->execute([$value, $tenantId, $key]);
            } else {
                $stmt = $db->prepare("INSERT INTO settings (tenant_id, key_name, key_value) VALUES (?, ?, ?)");
                $stmt->execute([$tenantId, $key, $value]);
            }
        }

        MobileApiResponse::success(null, 'تنظیمات طلا با موفقیت ذخیره شد.');
    }

    /**
     * انتشار دستی نرخ طلا
     * POST /api/v1/settings/gold/trigger (auth)
     */
    public function triggerGoldPublish(): void {
        $tenantId = $this->userId();
        $db       = $this->db();

        // دریافت تنظیمات طلا کاربر
        $stmt = $db->prepare("SELECT key_name, key_value FROM settings WHERE tenant_id = ? AND key_name IN ('gold_schedule', 'gold_api_url', 'gold_currency', 'gold_template', 'gold_auto_channels', 'gold_image_url')");
        $stmt->execute([$tenantId]);
        $rows = $stmt->fetchAll();

        $goldSettings = [];
        foreach ($rows as $row) {
            $goldSettings[$row['key_name']] = $row['key_value'];
        }

        $apiUrl = $goldSettings['gold_api_url'] ?? '';

        // دریافت نرخ‌ها از API
        $vals = GoldTicker::fetchValues($apiUrl);
        if (!$vals['success']) {
            MobileApiResponse::error($vals['message'] ?: 'خطا در دریافت نرخ طلا.', 400);
        }

        // دریافت کانال‌های هدف
        $channelIds = [];
        if (!empty($goldSettings['gold_auto_channels'])) {
            $channelIds = json_decode($goldSettings['gold_auto_channels'], true);
        }

        if (empty($channelIds) || !is_array($channelIds)) {
            $stmt = $db->prepare("SELECT id FROM channels WHERE tenant_id = ?");
            $stmt->execute([$tenantId]);
            $channelIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        }

        if (empty($channelIds)) {
            MobileApiResponse::error('هیچ کانالی برای ارسال وجود ندارد.', 400);
        }

        // ساخت پیام
        $title   = 'اعلام نرخ لحظه‌ای بازار طلا و سکه';
        $content = GoldTicker::buildMessage($tenantId, $vals);
        $image   = $goldSettings['gold_image_url'] ?? '';

        // ایجاد رکورد پست
        $stmt = $db->prepare("INSERT INTO posts (tenant_id, title, content, media_url, status) VALUES (?, ?, ?, ?, 'draft')");
        $stmt->execute([$tenantId, $title, $content, $image]);
        $postId = (int)$db->lastInsertId();

        // رزرو اتمیک سهمیه پیش از ارسال خارجی
        $db->prepare("UPDATE posts SET status = 'queued' WHERE id = ? AND tenant_id = ? AND status = 'draft'")->execute([$postId, $tenantId]);
        if (!\WHCM\Domain\Quota::reservePost($tenantId, $postId)) {
            $db->prepare("UPDATE posts SET status = 'failed' WHERE id = ? AND tenant_id = ? AND status = 'queued'")->execute([$postId, $tenantId]);
            MobileApiResponse::error('سهمیه ارسال پست شما به پایان رسیده است یا درخواست همزمان دیگری در حال پردازش است.', 409);
        }

        // ارسال به کانال‌ها
        $res = \WHCM\Domain\Sender::sendPostToChannels($tenantId, $channelIds, $title, $content, $image, $postId);

        if ($res['success']) {
            \WHCM\Domain\Quota::consumePostQuota($tenantId, $postId);
            MobileApiResponse::success(null, 'نرخ طلا با موفقیت به کانال‌ها ارسال شد.');
        } else {
            $db->prepare("UPDATE posts SET status = 'failed' WHERE id = ? AND tenant_id = ? AND status = 'sending'")->execute([$postId, $tenantId]);
            $errors = [];
            foreach ($res['channels'] ?? [] as $ch) {
                if (!$ch['success']) {
                    $errors[] = $ch['name'] . ': ' . $ch['message'];
                }
            }
            MobileApiResponse::error('خطا در ارسال: ' . implode('; ', $errors), 500);
        }
    }

    /**
     * ذخیره تنظیمات پیشرفته کاربر
     * PUT /api/v1/settings/advanced (auth)
     */
    public function saveAdvancedSettings(): void {
        $tenantId = $this->userId();
        $db       = $this->db();
        $input    = $this->input();

        $fields = [
            'ai_provider', 'ai_api_key', 'ai_model', 'ai_api_url',
            'auto_publish_woo', 'watermark_active', 'caption_format',
            'inbound_method', 'poll_interval',
            'link_name_1', 'link_url_1', 'link_name_2', 'link_url_2', 'link_name_3', 'link_url_3',
            'btn_1_text', 'btn_1_url', 'btn_2_text', 'btn_2_url',
        ];

        foreach ($fields as $field) {
            $value = $input[$field] ?? null;
            if ($value === null) {
                continue;
            }
            if ($field === 'ai_api_key') {
                $value = trim((string)$value);
                if ($value === '') {
                    continue;
                }
                $value = SecretStore::encrypt($value);
            }

            $stmt = $db->prepare("SELECT id FROM settings WHERE tenant_id = ? AND key_name = ? LIMIT 1");
            $stmt->execute([$tenantId, $field]);

            if ($stmt->fetch()) {
                $stmt = $db->prepare("UPDATE settings SET key_value = ? WHERE tenant_id = ? AND key_name = ?");
                $stmt->execute([$value, $tenantId, $field]);
            } else {
                $stmt = $db->prepare("INSERT INTO settings (tenant_id, key_name, key_value) VALUES (?, ?, ?)");
                $stmt->execute([$tenantId, $field, $value]);
            }
        }

        MobileApiResponse::success(null, 'تنظیمات پیشرفته با موفقیت ذخیره شد.');
    }

    /**
     * دریافت لیست پاسخ‌های خودکار
     * GET /api/v1/auto-responder (auth)
     */
    public function getAutoReplies(): void {
        $tenantId = $this->userId();
        $db       = $this->db();

        $stmt = $db->prepare("
            SELECT ar.*, c.name as channel_name, c.platform as channel_platform
            FROM auto_replies ar
            JOIN channels c ON ar.channel_id = c.id
            WHERE ar.tenant_id = ?
            ORDER BY ar.id DESC
        ");
        $stmt->execute([$tenantId]);
        $replies = $stmt->fetchAll();

        MobileApiResponse::success($replies);
    }

    /**
     * افزودن پاسخ خودکار جدید
     * POST /api/v1/auto-responder (auth)
     *
     * Input: channel_id (required), keyword (required), reply_text (required)
     */
    public function addAutoReply(): void {
        $tenantId = $this->userId();
        $db       = $this->db();
        $input    = $this->input();

        $errors = $this->validate([
            'channel_id' => 'required',
            'keyword'    => 'required',
            'reply_text' => 'required',
        ], $input);

        if (!empty($errors)) {
            MobileApiResponse::validationError($errors);
        }

        $channelId = (int)$input['channel_id'];
        $keyword   = trim($input['keyword']);
        $replyText = trim($input['reply_text']);

        // بررسی مالکیت کانال
        $stmt = $db->prepare("SELECT id FROM channels WHERE id = ? AND tenant_id = ? LIMIT 1");
        $stmt->execute([$channelId, $tenantId]);
        if (!$stmt->fetch()) {
            MobileApiResponse::error('کانال مورد نظر یافت نشد یا متعلق به شما نیست.', 404);
        }

        $stmt = $db->prepare("
            INSERT INTO auto_replies (tenant_id, channel_id, keyword, reply_text, active)
            VALUES (?, ?, ?, ?, 1)
        ");
        $stmt->execute([$tenantId, $channelId, $keyword, $replyText]);

        $replyId = (int)$db->lastInsertId();

        // دریافت رکورد ایجاد شده
        $stmt = $db->prepare("
            SELECT ar.*, c.name as channel_name, c.platform as channel_platform
            FROM auto_replies ar
            JOIN channels c ON ar.channel_id = c.id
            WHERE ar.id = ?
        ");
        $stmt->execute([$replyId]);
        $reply = $stmt->fetch();

        MobileApiResponse::success($reply, 'پاسخ خودکار با موفقیت اضافه شد.');
    }

    /**
     * حذف پاسخ خودکار
     * DELETE /api/v1/auto-responder/{id} (auth)
     *
     * @param string $id شناسه پاسخ خودکار از مسیر
     */
    public function deleteAutoReply(string $id): void {
        $tenantId = $this->userId();
        $db       = $this->db();
        $replyId  = (int)$id;

        $stmt = $db->prepare("DELETE FROM auto_replies WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$replyId, $tenantId]);

        if ($stmt->rowCount() === 0) {
            MobileApiResponse::error('پاسخ خودکار یافت نشد یا دسترسی ندارید.', 404);
        }

        MobileApiResponse::success(null, 'پاسخ خودکار با موفقیت حذف شد.');
    }

    /**
     * فعال/غیرفعال کردن پاسخگو خودکار برای یک کانال
     * POST /api/v1/auto-responder/toggle (auth)
     *
     * Input: channel_id, enabled (0 or 1)
     */
    public function toggleResponder(): void {
        $tenantId = $this->userId();
        $db       = $this->db();
        $input    = $this->input();

        $channelId = (int)($input['channel_id'] ?? 0);
        $enabled   = (int)($input['enabled'] ?? 0);

        if ($channelId <= 0) {
            MobileApiResponse::validationError(['channel_id' => 'شناسه کانال الزامی است.']);
        }

        // بررسی مالکیت کانال
        $stmt = $db->prepare("SELECT id FROM channels WHERE id = ? AND tenant_id = ? LIMIT 1");
        $stmt->execute([$channelId, $tenantId]);
        if (!$stmt->fetch()) {
            MobileApiResponse::error('کانال مورد نظر یافت نشد یا متعلق به شما نیست.', 404);
        }

        $keyName = 'responder_enabled_' . $channelId;

        $stmt = $db->prepare("SELECT id FROM settings WHERE tenant_id = ? AND key_name = ? LIMIT 1");
        $stmt->execute([$tenantId, $keyName]);

        if ($stmt->fetch()) {
            $stmt = $db->prepare("UPDATE settings SET key_value = ? WHERE tenant_id = ? AND key_name = ?");
            $stmt->execute([$enabled, $tenantId, $keyName]);
        } else {
            $stmt = $db->prepare("INSERT INTO settings (tenant_id, key_name, key_value) VALUES (?, ?, ?)");
            $stmt->execute([$tenantId, $keyName, $enabled]);
        }

        MobileApiResponse::success([
            'channel_id' => $channelId,
            'enabled'    => $enabled,
        ], 'وضعیت پاسخگو خودکار بروزرسانی شد.');
    }
}
