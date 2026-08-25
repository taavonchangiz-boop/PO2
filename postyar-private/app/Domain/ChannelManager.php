<?php
namespace WHCM\Domain;

use WHCM\Core\Bootstrap;
use WHCM\Core\SecretStore;
use WHCM\Domain\AntiAbuse;
use WHCM\Core\Auth;
use WHCM\Core\HttpClient;

/**
 * مدیریت کانال‌های اختصاصی کاربران با رعایت محدودیت‌های سهمیه و قوانین ضد تقلب
 *
 * @package WHCM\Domain
 */
class ChannelManager {

    /**
     * بررسی سهمیه و افزودن کانال جدید برای مستاجر فعلی
     *
     * @param string $name نام دلخواه کانال
     * @param string $platform 'telegram' یا 'bale'
     * @param string $channel_id شناسه کانال (شروع با @ یا آیدی عددی)
     * @param string $token توکن ربات تلگرام/بله
     * @return array ['success' => bool, 'message' => string]
     */
    public static function addChannel(string $name, string $platform, string $channel_id, string $token): array {
        $tenant_id = Auth::tenantId();
        if (!$tenant_id) {
            return ['success' => false, 'message' => 'کاربر احراز هویت نشده است.'];
        }

        // استانداردسازی شناسه کانال (حذف فاصله‌ها و اطمینان از فرمت صحیح)
        $channel_id = AntiAbuse::normalizeChannelId($channel_id);
        $platform = AntiAbuse::normalizePlatform($platform);

        if (empty($name) || empty($channel_id) || empty($token)) {
            return ['success' => false, 'message' => 'تمام فیلدهای الزامی را پر کنید.'];
        }

        if (!in_array($platform, ['telegram', 'bale'])) {
            return ['success' => false, 'message' => 'پلتفرم نامعتبر است.'];
        }

        $db = Bootstrap::getDB();

        // ۱. اعتبارسنجی هویت ربات و دسترسی واقعی به همین کانال پیش از Claim دائمی.
        // getMe به تنهایی کافی نیست؛ مهاجم نباید بتواند یک کانال عمومی متعلق به دیگری را
        // با توکن ربات خودش زودتر Claim کند. هر خطای شبکه نیز fail-closed است.
        $test = self::testBotConnection($platform, $token);
        if (!$test['success']) {
            return ['success'=>false, 'message'=>'اتصال ربات قابل تأیید نیست؛ ثبت کانال تا تأیید اتصال انجام نمی‌شود.'];
        }
        $access = self::verifyBotChannelAccess($platform, $token, $channel_id);
        if (!$access['success']) {
            return ['success'=>false, 'message'=>$access['message']];
        }

        // ۲. Entitlement + anti-abuse + registry + channel insert MUST be one transaction.
        // This prevents concurrent requests from exceeding max_channels and avoids claiming
        // a channel identity unless the actual channel row is committed too.
        $driver = Bootstrap::getConfig('database.driver', 'sqlite');
        if ($db->inTransaction()) return ['success'=>false, 'message'=>'تراکنش همزمان نامعتبر است.'];
        try {
            if ($driver === 'sqlite') $db->exec('BEGIN IMMEDIATE'); else $db->beginTransaction();
            $subSql = "SELECT s.id, p.max_channels FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.user_id = ? AND s.status = 'active' AND s.end_date > ? ORDER BY s.id DESC LIMIT 1";
            if ($driver === 'mysql') $subSql .= ' FOR UPDATE';
            $stmt = $db->prepare($subSql); $stmt->execute([$tenant_id, date('Y-m-d H:i:s')]); $sub = $stmt->fetch();
            if (!$sub) throw new \RuntimeException('اشتراک فعال برای افزودن کانال وجود ندارد.');
            $stmt = $db->prepare('SELECT COUNT(*) FROM channels WHERE tenant_id = ?'); $stmt->execute([$tenant_id]);
            if ((int)$stmt->fetchColumn() >= (int)$sub['max_channels']) throw new \RuntimeException('سقف کانال‌های پلن شما تکمیل شده است.');

            $claimIdentity = $platform . "\0" . $channel_id;
            $claimOwner = AntiAbuse::claimOwner($db, 'channel', $claimIdentity);
            if ($claimOwner !== null && $claimOwner !== $tenant_id) throw new \RuntimeException('این شناسه کانال قبلاً توسط حساب دیگری استفاده شده است.');
            if ($claimOwner === null && !AntiAbuse::claimChannel($db, $tenant_id, $platform, $channel_id)) throw new \RuntimeException('این شناسه کانال همزمان توسط حساب دیگری Claim شد.');

            $stmt = $db->prepare("SELECT owner_user_id FROM channel_registry WHERE platform = ? AND channel_id = ? LIMIT 1");
            $stmt->execute([$platform, $channel_id]); $registry = $stmt->fetch();
            if ($registry && (int)$registry['owner_user_id'] !== $tenant_id) throw new \RuntimeException('این کانال قبلاً توسط کاربر دیگری ثبت شده است.');
            if (!$registry) {
                $stmt = $db->prepare("INSERT INTO channel_registry (platform, channel_id, owner_user_id) VALUES (?, ?, ?)");
                $stmt->execute([$platform, $channel_id, $tenant_id]);
            }
            $stmt = $db->prepare("SELECT id FROM channels WHERE tenant_id = ? AND platform = ? AND channel_id = ? LIMIT 1");
            $stmt->execute([$tenant_id, $platform, $channel_id]);
            if ($stmt->fetch()) throw new \RuntimeException('این کانال در حال حاضر در پنل شما فعال است.');

            $stmt = $db->prepare("INSERT INTO channels (tenant_id, name, platform, channel_id, token, link_config, button_config) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $encryptedToken = SecretStore::encrypt($token);
            $stmt->execute([$tenant_id, $name, $platform, $channel_id, $encryptedToken,
                json_encode([['name'=>'مشاهده سایت','url'=>''],['name'=>'کانال تلگرام','url'=>''],['name'=>'کانال بله','url'=>'']], JSON_UNESCAPED_UNICODE),
                json_encode(['active'=>false,'buttons'=>[['text'=>'پشتیبانی','url'=>''],['text'=>'ثبت سفارش','url'=>'']],], JSON_UNESCAPED_UNICODE)]);
            $newId = (int)$db->lastInsertId();
            $db->commit();

            $stmt_new = $db->prepare("SELECT * FROM channels WHERE id = ? AND tenant_id = ? LIMIT 1");
            $stmt_new->execute([$newId, $tenant_id]); $new_channel = $stmt_new->fetch();
            if ($new_channel) self::tryActivateWebhook($new_channel);
            return ['success'=>true, 'message'=>'کانال جدید با موفقیت به پنل شما متصل شد.' . $warning_msg];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            return ['success'=>false, 'message'=>$e->getMessage()];
        }

    }
    /**
     * تست زنده اتصال ربات به سرورهای تلگرام/بله
     */
    /**
     * Verify that the supplied bot token can actually access the requested channel.
     * getMe only proves that the token is valid; it does NOT prove ownership/access
     * to the channel. This check must happen before an immutable anti-abuse claim.
     */
    public static function verifyBotChannelAccess(string $platform, string $token, string $channelId): array {
        $platform = AntiAbuse::normalizePlatform($platform);
        $channelId = AntiAbuse::normalizeChannelId($channelId);
        $token = trim($token);

        if (!in_array($platform, ['telegram', 'bale'], true) || $token === '' || $channelId === '') {
            return ['success' => false, 'message' => 'اطلاعات اتصال کانال نامعتبر است.'];
        }

        $baseUrl = ($platform === 'bale') ? 'https://tapi.bale.ai/bot' : 'https://api.telegram.org/bot';
        $url = $baseUrl . $token . '/getChat?' . http_build_query(['chat_id' => $channelId]);
        $res = HttpClient::get($url, [], 10);

        if (!$res['success']) {
            return ['success' => false, 'message' => 'دسترسی ربات به کانال قابل تأیید نیست؛ ثبت یا تغییر کانال متوقف شد.'];
        }

        $data = json_decode((string)$res['body'], true);
        if (!is_array($data) || empty($data['ok']) || !is_array($data['result'] ?? null)) {
            return ['success' => false, 'message' => (string)($data['description'] ?? 'ربات به این کانال دسترسی ندارد.')];
        }

        $result = $data['result'];
        $requested = $channelId;
        $returnedUsername = AntiAbuse::normalizeChannelId((string)($result['username'] ?? ''));
        $returnedId = (string)($result['id'] ?? '');
        $matches = ($requested !== '' && (hash_equals($returnedId, $requested) || ($returnedUsername !== '' && hash_equals($returnedUsername, $requested))));

        if (!$matches) {
            return ['success' => false, 'message' => 'شناسه کانال با کانالی که ربات به آن دسترسی دارد مطابقت ندارد.'];
        }

        // Telegram exposes chat type. If present, reject private/group chats when a
        // channel identity was requested; Bale may expose a different type, so absence
        // of the field is not treated as failure.
        if ($platform === 'telegram' && isset($result['type']) && !in_array($result['type'], ['channel', 'supergroup'], true)) {
            return ['success' => false, 'message' => 'شناسه واردشده یک کانال معتبر تلگرام نیست.'];
        }

        return ['success' => true, 'message' => 'دسترسی ربات به کانال با موفقیت تأیید شد.'];
    }

    public static function testBotConnection(string $platform, string $token): array {
        $base_url = ($platform === 'bale') ? 'https://tapi.bale.ai/bot' : 'https://api.telegram.org/bot';
        $url = $base_url . trim($token) . '/getMe';

        // استفاده از کلاس کمکی برای ارسال درخواست
        $res = HttpClient::get($url, [], 10);
        if (!$res['success']) {
            return ['success' => false, 'message' => $res['error'] ?? 'خطای شبکه یا مسدود بودن هاست.'];
        }

        $data = json_decode($res['body'], true);
        if (!empty($data['ok'])) {
            $bot_name = $data['result']['first_name'] ?? 'Bot';
            $bot_username = $data['result']['username'] ?? '';
            return [
                'success' => true,
                'message' => "اتصال موفقیت‌آمیز بود! نام ربات: {$bot_name} (@{$bot_username})"
            ];
        }

        return [
            'success' => false,
            'message' => $data['description'] ?? 'توکن نامعتبر است.'
        ];
    }

    /**
     * دریافت لیست کانال‌های متصل کاربر
     */
    public static function getTenantChannels(?int $tenant_id = null): array {
        $tenant_id = $tenant_id ?? Auth::tenantId();
        if (!$tenant_id) {
            return [];
        }

        $db = Bootstrap::getDB();
        $stmt = $db->prepare("SELECT * FROM channels WHERE tenant_id = ? ORDER BY id DESC");
        $stmt->execute([$tenant_id]);
        $rows = $stmt->fetchAll();
         foreach ($rows as &$row) { $row['token'] = SecretStore::decrypt((string)$row['token']); }
         unset($row);
         return $rows;
    }

    /**
     * دریافت اطلاعات یک کانال خاص با اعتبارسنجی مالکیت
     */
    public static function getChannel(int $id, ?int $tenant_id = null): ?array {
        $tenant_id = $tenant_id ?? Auth::tenantId();
        if (!$tenant_id) {
            return null;
        }

        $db = Bootstrap::getDB();
        // برای امنیت و رعایت حریم خصوصی مستاجر، حتماً شرط tenant_id اعمال می‌شود
        $stmt = $db->prepare("SELECT * FROM channels WHERE id = ? AND tenant_id = ? LIMIT 1");
        $stmt->execute([$id, $tenant_id]);
        $channel = $stmt->fetch();
        if ($channel) { $channel['token'] = SecretStore::decrypt((string)$channel['token']); }
         return $channel ?: null;
    }

    /**
     * حذف کانال از پنل کاربر (ولی در رجیستری ضدتقلب برای همیشه باقی می‌ماند)
     */
    public static function deleteChannel(int $id): bool {
        $tenant_id = Auth::tenantId();
        if (!$tenant_id) {
            return false;
        }

        $channel = self::getChannel($id, $tenant_id);
        if (!$channel) {
            return false;
        }

        // اگر وبهوک فعال بود، ابتدا آن را حذف می‌کنیم
        if ($channel['webhook_active']) {
            self::deleteWebhook($channel);
        }

        $db = Bootstrap::getDB();
        $stmt = $db->prepare("DELETE FROM channels WHERE id = ? AND tenant_id = ?");
        return $stmt->execute([$id, $tenant_id]);
    }

    /**
     * ثبت آدرس وبهوک برای دریافت پیام‌ها و پاسخگو بودن خودکار
     */
    public static function setWebhook(array $channel): array {
        $platform = $channel['platform'];
        $token = trim($channel['token']);
        $base_url = ($platform === 'bale') ? 'https://tapi.bale.ai/bot' : 'https://api.telegram.org/bot';

        // آدرس روت دریافت وبهوک در سیستم SaaS
        $app_url = Bootstrap::getConfig('app.url', 'http://localhost');
        $secret = trim((string)($channel['webhook_secret'] ?? ''));
        if ($secret === '') {
            $secret = bin2hex(random_bytes(32));
            $db = Bootstrap::getDB();
            $db->prepare('UPDATE channels SET webhook_secret = ? WHERE id = ? AND tenant_id = ?')
                ->execute([$secret, (int)$channel['id'], (int)$channel['tenant_id']]);
        }
        $webhook_url = rtrim($app_url, '/') . '/api/webhook?channel_id=' . (int)$channel['id'] . '&secret=' . rawurlencode($secret);
        
        $url = $base_url . $token . '/setWebhook';
        $body = ['url' => $webhook_url];

        if ($platform === 'telegram' && !empty($channel['webhook_secret'])) {
            $body['secret_token'] = $channel['webhook_secret'];
        }

        $res = HttpClient::post($url, $body, [], 15);
        if (!$res['success']) {
            return ['success' => false, 'message' => 'خطا در ارتباط با سرور مقصد.'];
        }

        $data = json_decode($res['body'], true);
        if (!empty($data['ok'])) {
            $db = Bootstrap::getDB();
            $db->prepare("UPDATE channels SET webhook_active = 1 WHERE id = ? AND tenant_id = ?")->execute([(int)$channel['id'], (int)($channel['tenant_id'] ?? 0)]);
            return ['success' => true, 'message' => 'وبهوک ربات با موفقیت فعال شد.'];
        }

        return ['success' => false, 'message' => $data['description'] ?? 'خطا در ثبت وبهوک ربات.'];
    }

    /**
     * تلاش برای فعال‌سازی وبهوک روی یک کانال. در صورت عدم موفقیت، حالت Polling فعال می‌ماند.
     */
    public static function tryActivateWebhook(array $channel): void {
        try {
            $result = self::setWebhook($channel);
            if ($result['success']) {
                error_log('[Postyar] Webhook set for channel #' . (int)$channel['id'] . ' (' . $channel['platform'] . ')');
            } else {
                error_log('[Postyar] Webhook failed for channel #' . (int)$channel['id'] . ': ' . $result['message']);
            }
        } catch (\Throwable $e) {
            error_log('[Postyar] Webhook error for channel #' . (int)$channel['id'] . ': ' . $e->getMessage());
        }
    }

    /**
     * حذف آدرس وبهوک
     */
    public static function deleteWebhook(array $channel): bool {
        $platform = $channel['platform'];
        $token = trim($channel['token']);
        $base_url = ($platform === 'bale') ? 'https://tapi.bale.ai/bot' : 'https://api.telegram.org/bot';

        $url = $base_url . $token . '/deleteWebhook';
        $res = HttpClient::post($url, [], [], 15);

        if ($res['success']) {
            $data = json_decode($res['body'], true);
            if (!empty($data['ok'])) {
                $db = Bootstrap::getDB();
                $db->prepare("UPDATE channels SET webhook_active = 0 WHERE id = ? AND tenant_id = ?")->execute([(int)$channel['id'], (int)($channel['tenant_id'] ?? 0)]);
                return true;
            }
        }

        return false;
    }
}
