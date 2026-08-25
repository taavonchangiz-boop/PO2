<?php
namespace WHCM\Api\Controllers;

use WHCM\Api\MobileApiResponse;
use WHCM\Core\SecretStore;
use WHCM\Domain\ChannelManager;
use WHCM\Core\Bootstrap;
use WHCM\Domain\AntiAbuse;

/**
 * کنترلر API مدیریت کانال‌ها
 *
 * عملیات CRUD کانال‌ها برای اپلیکیشن موبایل
 *
 * @package WHCM\Api\Controllers
 */
class ChannelApiController extends \WHCM\Api\MobileApiController {

    /**
     * دریافت لیست کانال‌های کاربر
     * GET /api/v1/channels (auth)
     */
    public function index(): void {
        $tenant_id = $this->userId();
        $channels = ChannelManager::getTenantChannels($tenant_id);
        foreach ($channels as &$channel) { unset($channel['token']); }
        unset($channel);
        MobileApiResponse::success($channels);
    }

    /**
     * افزودن کانال جدید
     * POST /api/v1/channels (auth)
     *
     * Input: name (required), platform (required), channel_id (required), token (required)
     */
    public function store(): void {
        $tenant_id = $this->userId();
        $input = $this->input();

        // اعتبارسنجی فیلدهای الزامی
        $errors = $this->validate([
            'name'       => 'required',
            'platform'   => 'required',
            'channel_id' => 'required',
            'token'      => 'required',
        ], $input);

        if (!empty($errors)) {
            MobileApiResponse::validationError($errors);
        }

        $name       = trim($input['name']);
        $platform   = trim($input['platform']);
        $channel_id = trim($input['channel_id']);
        $token      = trim($input['token']);

        $result = ChannelManager::addChannel($name, $platform, $channel_id, $token);

        if (!$result['success']) {
            MobileApiResponse::error($result['message']);
        }

        // بازگرداندن کانال جدید ایجاد شده
        $channels = ChannelManager::getTenantChannels($tenant_id);
        $newChannel = null;
        foreach ($channels as $ch) {
            if ($ch['channel_id'] === $channel_id && $ch['platform'] === $platform) {
                $newChannel = $ch;
                break;
            }
        }

        if (is_array($newChannel)) { unset($newChannel['token']); }
        MobileApiResponse::success($newChannel, $result['message']);
    }

    /**
     * دریافت اطلاعات یک کانال
     * GET /api/v1/channels/{id} (auth)
     *
     * @param string $id شناسه کانال از مسیر
     */
    public function show(string $id): void {
        $tenant_id = $this->userId();
        $channel = ChannelManager::getChannel((int)$id, $tenant_id);

        if (!$channel) {
            MobileApiResponse::notFound('کانال مورد نظر یافت نشد.');
        }

        unset($channel['token']);
        MobileApiResponse::success($channel);
    }

    /**
     * بروزرسانی کانال
     * PUT /api/v1/channels/{id} (auth)
     *
     * @param string $id شناسه کانال از مسیر
     */
    public function update(string $id): void {
        $tenant_id = $this->userId();
        $channelId = (int)$id;

        // دریافت کانال فعلی
        $channel = ChannelManager::getChannel($channelId, $tenant_id);
        if (!$channel) {
            MobileApiResponse::notFound('کانال مورد نظر یافت نشد.');
        }

        $input = $this->input();

        // اعتبارسنجی فیلدهای الزامی
        $errors = $this->validate([
            'name'          => 'required',
            'platform'      => 'required',
            'channel_id_val'=> 'required',
            'token'         => 'required',
        ], $input);

        if (!empty($errors)) {
            MobileApiResponse::validationError($errors);
        }

        $name       = trim($input['name']);
        $platform   = AntiAbuse::normalizePlatform($input['platform_val'] ?? $input['platform']);
        $channel_id = AntiAbuse::normalizeChannelId($input['channel_id_val']);
        $token      = trim($input['token']);

        // پردازش ساختار لینک‌های سه‌گانه
        $links = [
            ['name' => trim($input['link_name_1'] ?? ''), 'url' => trim($input['link_url_1'] ?? '')],
            ['name' => trim($input['link_name_2'] ?? ''), 'url' => trim($input['link_url_2'] ?? '')],
            ['name' => trim($input['link_name_3'] ?? ''), 'url' => trim($input['link_url_3'] ?? '')],
        ];

        // پردازش دکمه‌های شیشه‌ای تعاملی
        $buttons_active = !empty($input['buttons_active']);
        $buttons = [
            ['text' => trim($input['btn_text_1'] ?? ''), 'url' => trim($input['btn_url_1'] ?? '')],
            ['text' => trim($input['btn_text_2'] ?? ''), 'url' => trim($input['btn_url_2'] ?? '')],
        ];

        $button_config = [
            'active'  => $buttons_active,
            'buttons' => $buttons,
        ];

        $db = Bootstrap::getDB();

        // Changing the bot token or channel identity is security-sensitive.
        // Verify that the new token can access the exact target before changing the
        // persistent identity; getMe alone is insufficient.
        $tokenChanged = trim((string)$channel['token']) !== $token;
        $changedIdentity = AntiAbuse::normalizePlatform($channel['platform']) !== $platform
            || AntiAbuse::normalizeChannelId($channel['channel_id']) !== $channel_id;
        if ($tokenChanged || $changedIdentity) {
            $access = ChannelManager::verifyBotChannelAccess($platform, $token, $channel_id);
            if (!$access['success']) {
                MobileApiResponse::error($access['message'], 409);
            }
        }

        // هر تغییر هویت کانال باید از همان Claim دائمی ضدتقلب عبور کند؛ API نباید مسیر دورزن داشته باشد.
        $db = Bootstrap::getDB();
        if ($changedIdentity) {
            $driver = Bootstrap::getConfig('database.driver', 'sqlite');
            if ($db->inTransaction()) MobileApiResponse::error('تراکنش همزمان نامعتبر است.', 409);
            if ($driver === 'sqlite') $db->exec('BEGIN IMMEDIATE'); else $db->beginTransaction();
            try {
                $claim = AntiAbuse::claimChannel($db, $tenant_id, $platform, $channel_id);
                if (!$claim) {
                    $owner = AntiAbuse::claimOwner($db, 'channel', $platform . "\0" . $channel_id);
                    if ($owner !== $tenant_id) throw new \RuntimeException('این شناسه کانال قبلاً توسط حساب دیگری Claim شده است.');
                }
                $stmt = $db->prepare("SELECT owner_user_id FROM channel_registry WHERE platform = ? AND channel_id = ? LIMIT 1");
                $stmt->execute([$platform, $channel_id]);
                $reg = $stmt->fetch();
                if ($reg && (int)$reg['owner_user_id'] !== $tenant_id) throw new \RuntimeException('این شناسه کانال قبلاً توسط کاربر دیگری ثبت شده است.');
                if (!$reg) {
                    $stmt = $db->prepare("INSERT INTO channel_registry (platform, channel_id, owner_user_id) VALUES (?, ?, ?)");
                    $stmt->execute([$platform, $channel_id, $tenant_id]);
                }
                $stmt = $db->prepare("UPDATE channels SET name = ?, platform = ?, channel_id = ?, token = ?, link_config = ?, button_config = ? WHERE id = ? AND tenant_id = ?");
                $stmt->execute([$name, $platform, $channel_id, SecretStore::encrypt($token), json_encode($links, JSON_UNESCAPED_UNICODE), json_encode($button_config, JSON_UNESCAPED_UNICODE), $channelId, $tenant_id]);
                $db->commit();
            } catch (\Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                MobileApiResponse::error($e->getMessage(), 409);
            }
        } else {
            $stmt = $db->prepare("UPDATE channels SET name = ?, token = ?, link_config = ?, button_config = ? WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$name, SecretStore::encrypt($token), json_encode($links, JSON_UNESCAPED_UNICODE), json_encode($button_config, JSON_UNESCAPED_UNICODE), $channelId, $tenant_id]);
        }

        // بازگرداندن کانال بروزرسانی شده
        $updated = ChannelManager::getChannel($channelId, $tenant_id);
        unset($updated['token']);
        MobileApiResponse::success($updated, 'تنظیمات کانال با موفقیت بروزرسانی شد.');
    }

    /**
     * حذف کانال
     * DELETE /api/v1/channels/{id} (auth)
     *
     * @param string $id شناسه کانال از مسیر
     */
    public function delete(string $id): void {
        $tenant_id = $this->userId();
        $channelId = (int)$id;

        // ابتدا بررسی مالکیت
        $channel = ChannelManager::getChannel($channelId, $tenant_id);
        if (!$channel) {
            MobileApiResponse::notFound('کانال مورد نظر یافت نشد.');
        }

        $deleted = ChannelManager::deleteChannel($channelId);

        if (!$deleted) {
            MobileApiResponse::error('خطا در حذف کانال.');
        }

        MobileApiResponse::success(null, 'کانال با موفقیت حذف گردید.');
    }
}
