<?php
namespace WHCM\Domain;

use WHCM\Core\Bootstrap;
use WHCM\Core\Cache;
use WHCM\Domain\Quota;

/**
 * مدیریت نرخ لحظه‌ای بازار طلا و سکه به صورت چندکاربره (Multi-Tenant)
 *
 * @package WHCM\Domain
 */
class GoldTicker {

    /**
     * Validate tenant-controlled outbound API URLs.
     * Only HTTPS public hosts are allowed; private/link-local/loopback ranges are blocked.
     */
    public static function isAllowedApiUrl(string $url): bool {
        $parts = parse_url(trim($url));
        if (!$parts || (($parts['scheme'] ?? '') !== 'https') || empty($parts['host'])) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) && !in_array((int)$parts['port'], [443], true)) {
            return false;
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return false;
        }
        $ips = gethostbynamel($host);
        if ($ips === false || !$ips) return false;
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }
        return true;
    }


    /**
     * دریافت و استخراج مقادیر خام طلا/سکه/انس از API
     *
     * @param string $url آدرس اختصاصی API طلا
     * @return array
     */
    public static function fetchValues(string $url = ''): array {
        if (empty($url)) {
            // اول تنظیمات ادمین کل (gold_custom_api_url) را چک می‌کنیم
            try {
                $db = Bootstrap::getDB();
                $stmt = $db->prepare("SELECT key_value FROM settings WHERE tenant_id = 0 AND key_name = 'gold_custom_api_url' LIMIT 1");
                $stmt->execute();
                $admin_url = $stmt->fetchColumn();
                if (!empty($admin_url)) {
                    $url = $admin_url;
                }
            } catch (\Throwable $e) {}
        }

        if (empty($url)) {
            $url = Bootstrap::getConfig('defaults.gold_api_url');
        }

        if (!self::isAllowedApiUrl($url)) {
            return [
                'success' => false,
                'g18' => 0,
                'coin' => 0,
                'oz' => 0,
                'message' => 'آدرس API نرخ طلا مجاز نیست.'
            ];
        }

        if (empty($url)) {
            return [
                'success' => false,
                'g18' => 0,
                'coin' => 0,
                'oz' => 0,
                'message' => 'آدرس API نرخ طلا تنظیم نشده است.'
            ];
        }

        // نرخ بازار در یک بازه کوتاه cache می‌شود تا هزاران dashboard همزمان
        // به سرویس خارجی ضربه نزنند. Cache هرگز منبع حقیقت مالی نیست.
        $cacheKey = 'gold:values:' . hash('sha256', $url);
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['success'])) {
            return $cached;
        }

        // ارسال درخواست HTTP از طریق کلاینت پلتفرم
        $res = \WHCM\Core\HttpClient::get($url, [], 15);
        if (!$res['success']) {
            return [
                'success' => false,
                'g18' => 0,
                'coin' => 0,
                'oz' => 0,
                'message' => !empty($res['error']) ? $res['error'] : 'خطا در برقراری ارتباط با سرویس نرخ طلا.'
            ];
        }

        $data = json_decode($res['body'], true);
        if (!is_array($data)) {
            return [
                'success' => false,
                'g18' => 0,
                'coin' => 0,
                'oz' => 0,
                'message' => 'پاسخ دریافتی از API طلا معتبر (JSON) نیست.'
            ];
        }

        // بهره‌گیری از الگوریتم پارسر هوشمند و جستجوی بازگشتی بر مبنای کلیدواژه‌ها
        $g18  = self::findValue($data, ['18', 'g18', 'gold', 'tala', 'geram', 'geram18', 'shab', 'طلا', 'طلای']);
        $coin = self::findValue($data, ['coin', 'seke', 'sekke', 'sekk', 'sagh', 'sakke', 'bahar', 'emis', 'emami', 'rob', 'nim', 'سکه']);
        $oz   = self::findValue($data, ['ounce', 'ons', 'global', 'global_ounce', 'gold_usd', 'world', 'جهانی', 'انس']);

        if ($g18 === '' && $coin === '' && $oz === '') {
            $sample = function_exists('mb_substr') ? mb_substr($res['body'], 0, 300) : substr($res['body'], 0, 300);
            return [
                'success' => false,
                'g18' => 0,
                'coin' => 0,
                'oz' => 0,
                'message' => 'شاخص‌های قیمت در پاسخ سرویس یافت نشد. پاسخ API: ' . $sample
            ];
        }

        $result = [
            'success' => true,
            'g18'     => (float) $g18,
            'coin'    => (float) $coin,
            'oz'      => (float) $oz,
            'message' => ''
        ];
        Cache::set($cacheKey, $result, 20);
        return $result;
    }

    /**
     * جستجوی هوشمند و بازگشتی مقادیر قیمت بر اساس کلیدواژه‌ها (پارسر هوشمند)
     */
    private static function findValue(array $data, array $needles): string {
        foreach ($data as $k => $v) {
            $key = strtolower((string) $k);
            if (is_array($v) || is_object($v)) {
                $found = self::findValue((array) $v, $needles);
                if ($found !== '') {
                    return $found;
                }
                continue;
            }
            if (!is_string($v) && !is_numeric($v)) {
                continue;
            }
            $c = TextFormat::en_num((string) $v);
            if (!is_numeric($c)) {
                continue;
            }
            foreach ($needles as $n) {
                if (strpos($key, strtolower($n)) !== false) {
                    return $c;
                }
            }
            if (self::isGenericKey($key) && isset($data['name']) && is_string($data['name'])) {
                $name = self::utf8Lower((string) $data['name']);
                foreach ($needles as $n) {
                    if (strpos($name, self::utf8Lower($n)) !== false) {
                        return $c;
                    }
                }
            }
        }
        return '';
    }

    private static function isGenericKey(string $key): bool {
        return in_array($key, ['value', 'price', 'val', 'current', 'now', 'rate', 'current_price', 'amount'], true);
    }

    private static function utf8Lower(string $str): string {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($str, 'UTF-8');
        }
        return strtolower($str);
    }

    /**
     * ساخت متن نهایی بر اساس مقادیر و قالب‌بندی اختصاصی مستاجر
     */
    public static function buildMessage(int $tenant_id, array $vals): string {
        $db = Bootstrap::getDB();

        // دریافت تنظیمات اختصاصی قالب پیام مستاجر
        $stmt = $db->prepare("SELECT key_name, key_value FROM settings WHERE tenant_id = ? AND key_name IN ('gold_template', 'gold_currency')");
        $stmt->execute([$tenant_id]);
        $settings_rows = $stmt->fetchAll();

        $settings = [];
        foreach ($settings_rows as $row) {
            $settings[$row['key_name']] = $row['key_value'];
        }

        $template = $settings['gold_template'] ?? "🌟 اعلام نرخ لحظه‌ای بازار طلا و سکه\n\nهر گرم طلا ۱۸ عیار: {g18k}\nسکه تمام بهار آزادی: {coin}\nانس جهانی: {oz}\n\n⏰ به‌روزشده در: {time}";
        
        $g18  = $vals['success'] ? $vals['g18'] : 0;
        $coin = $vals['success'] ? $vals['coin'] : 0;
        $oz   = $vals['success'] ? $vals['oz'] : 0;

        $search  = ['{g18k}', '{coin}', '{oz}', '{time}'];
        $replace = [
            TextFormat::format_price($g18, 'g18', $settings),
            TextFormat::format_price($coin, 'coin', $settings),
            TextFormat::format_price($oz, 'oz', $settings),
            TextFormat::now_jalali()
        ];

        return str_replace($search, $replace, $template);
    }

    /**
     * پردازش خودکار نرخ طلا برای یک مستاجر خاص
     */
    public static function tick(int $tenant_id): bool {
        $db = Bootstrap::getDB();

        // بررسی اینکه آیا کاربر اشتراک فعال با قابلیت نمایش طلا دارد یا خیر
        $quota = Quota::getTenantQuota($tenant_id);
        if (!$quota['has_active_sub'] || empty($quota['features']['gold_ticker'])) {
            return false;
        }

        // دریافت تنظیمات طلا برای این مستاجر
        $stmt = $db->prepare("SELECT key_name, key_value FROM settings WHERE tenant_id = ? AND key_name IN ('gold_schedule', 'gold_api_url', 'gold_auto_channels', 'gold_image_url', 'last_gold_prices')");
        $stmt->execute([$tenant_id]);
        $settings_rows = $stmt->fetchAll();

        $settings = [];
        foreach ($settings_rows as $row) {
            $settings[$row['key_name']] = $row['key_value'];
        }

        $schedule = $settings['gold_schedule'] ?? 'manual';
        if ($schedule === 'manual') {
            return false;
        }

        // دریافت آدرس API مستاجر یا استفاده از پیش‌فرض سامانه
        $api_url = $settings['gold_api_url'] ?? '';
        $vals = self::fetchValues($api_url);
        if (!$vals['success']) {
            return false;
        }

        // تشخیص تغییر قیمت جهت پرهیز از ارسال پیام‌های تکراری
        $last_prices = $settings['last_gold_prices'] ?? '';
        $current_prices_key = round($vals['g18'], 2) . '|' . round($vals['coin'], 2) . '|' . round($vals['oz'], 2);

        if ($last_prices === $current_prices_key) {
            return false; // بدون تغییر قیمت؛ پستی ارسال نمی‌شود
        }

        // کانال‌های هدف مستاجر جهت انتشار خودکار
        $channel_ids = [];
        if (!empty($settings['gold_auto_channels'])) {
            $channel_ids = json_decode($settings['gold_auto_channels'], true);
        }

        if (empty($channel_ids)) {
            // در صورت عدم تنظیم، به همه کانال‌های فعال مستاجر ارسال می‌شود
            $stmt = $db->prepare("SELECT id FROM channels WHERE tenant_id = ?");
            $stmt->execute([$tenant_id]);
            $channel_ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        }

        if (empty($channel_ids)) {
            return false;
        }

        // ساخت پیام و انتشار
        $title = 'اعلام نرخ لحظه‌ای بازار طلا و سکه';
        $content = self::buildMessage($tenant_id, $vals);
        $image = $settings['gold_image_url'] ?? '';

        // ایجاد یک رکورد پست سیستمی برای سوابق مستاجر
        $stmt = $db->prepare("INSERT INTO posts (tenant_id, title, content, media_url, status) VALUES (?, ?, ?, ?, 'draft')");
        $stmt->execute([$tenant_id, $title, $content, $image]);
        $post_id = (int)$db->lastInsertId();

        // رزرو اتمیک سهمیه قبل از ارسال خارجی
        $db->prepare("UPDATE posts SET status = 'queued' WHERE id = ? AND tenant_id = ? AND status = 'draft'")->execute([$post_id, $tenant_id]);
        if (!Quota::reservePost($tenant_id, $post_id)) {
            $db->prepare("UPDATE posts SET status = 'failed' WHERE id = ? AND tenant_id = ? AND status = 'queued'")->execute([$post_id, $tenant_id]);
            return false;
        }

        // ارسال به کانال‌ها
        $res = Sender::sendPostToChannels($tenant_id, $channel_ids, $title, $content, $image, $post_id);

        if ($res['success']) {
            Quota::consumePostQuota($tenant_id, $post_id);
        } else {
            $db->prepare("UPDATE posts SET status = 'failed' WHERE id = ? AND tenant_id = ? AND status = 'sending'")->execute([$post_id, $tenant_id]);
        }

        // ذخیره نرخ جدید در تنظیمات حافظه موقت مستاجر جهت مقایسه بعدی (سازگار با تمامی دیتابیس‌ها)
        $stmt = $db->prepare("SELECT id FROM settings WHERE tenant_id = ? AND key_name = 'last_gold_prices' LIMIT 1");
        $stmt->execute([$tenant_id]);
        if ($stmt->fetch()) {
            $stmt = $db->prepare("UPDATE settings SET key_value = ? WHERE tenant_id = ? AND key_name = 'last_gold_prices'");
            $stmt->execute([$current_prices_key, $tenant_id]);
        } else {
            $stmt = $db->prepare("INSERT INTO settings (tenant_id, key_name, key_value) VALUES (?, 'last_gold_prices', ?)");
            $stmt->execute([$tenant_id, $current_prices_key]);
        }

        return true;
    }

    /**
     * اجرای زمان‌بندی نرخ طلا برای تمامی مستاجرین فعال سامانه (توسط کرون جاب کلی)
     */
    public static function tickAll() {
        $db = Bootstrap::getDB();
        $now = date('Y-m-d H:i:s');

        // دریافت شناسه‌ کاربران دارای اشتراک فعال
        $stmt = $db->prepare("SELECT DISTINCT user_id FROM subscriptions WHERE status = 'active' AND end_date > ?");
        $stmt->execute([$now]);
        $active_users = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($active_users as $tenant_id) {
            self::tick((int)$tenant_id);
        }
    }
}
