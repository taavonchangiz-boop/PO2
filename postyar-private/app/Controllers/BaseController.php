<?php
namespace WHCM\Controllers;

use WHCM\Core\Bootstrap;
use WHCM\Core\SecretStore;
use WHCM\Core\Auth;
use WHCM\Core\Csrf;

/**
 * کنترلر پایه — شامل متدهای مشترک تمام کنترلرها
 *
 * @package WHCM\Controllers
 */
abstract class BaseController {

    /**
     * نگاشت MIME type معتبر به فرمت‌های مجاز
     */
    private static array $allowedMimeTypes = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
    ];

    /**
     * بررسی احراز هویت کاربر
     */
    protected function checkAuth() {
        if (!Auth::check()) {
            $this->setFlashMessage('جهت دسترسی به این بخش ابتدا وارد حساب کاربری خود شوید.');
            $this->redirect('/');
        }
    }

    /**
     * بررسی دسترسی سوپرادمین (با پشتیبانی از IP Whitelist)
     */
    protected function checkSuperAdmin() {
        // ---- اعتبارسنجی IP Whitelist ----
        $whitelist = Bootstrap::getConfig('security.admin_ip_whitelist', []);
        if (!empty($whitelist) && is_array($whitelist)) {
            $clientIp = $this->getClientIp();
            if (!in_array($clientIp, $whitelist, true)) {
                http_response_code(403);
                exit('دسترسی غیرمجاز: IP شما در لیست مجاز نیست.');
            }
        }

        if (!Auth::check() || !Auth::isSuperAdmin()) {
            $this->setFlashMessage('دسترسی شما غیرمجاز است.');
            $this->redirect('/');
        }
    }

    /**
     * بررسی دسترسی مدیر یا پشتیبان (بدون IP Whitelist)
     */
    protected function checkAdminOrSupport() {
        if (!Auth::check() || !Auth::isAdminOrSupport()) {
            $this->setFlashMessage('دسترسی شما غیرمجاز است.');
            $this->redirect('/');
        }
    }

    /**
     * دریافت IP واقعی کاربر (با پشتیبانی از Trusted Proxies)
     *
     * @return string
     */
    private function getClientIp(): string {
        $trustedProxies = Bootstrap::getConfig('security.trusted_proxies', []);

        // اگر آیپی فعلی یکی از پروکسی‌های معتبر نبود، از REMOTE_ADDR استفاده کن
        if (!empty($trustedProxies) && in_array($_SERVER['REMOTE_ADDR'] ?? '', $trustedProxies, true)) {
            // ترتیب اولویت: X-Forwarded-For → X-Real-IP
            $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            if (!empty($forwarded)) {
                $ips = explode(',', $forwarded);
                return trim($ips[0]);
            }
            $realIp = $_SERVER['HTTP_X_REAL_IP'] ?? '';
            if (!empty($realIp)) {
                return trim($realIp);
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * هدایت به مسیر دیگر
     */
    protected function redirect(string $path) {
        if (strpos($path, 'http://') !== 0 && strpos($path, 'https://') !== 0) {
            $path = Bootstrap::getRouteUrl($path);
        }
        header("Location: " . $path);
        exit;
    }

    /**
     * تنظیم پیام فلش برای نمایش در صفحه بعد
     */
    protected function setFlashMessage(string $msg) {
        $_SESSION['flash_msg'] = $msg;
    }

    /**
     * دریافت و پاک کردن پیام فلش
     */
    protected function getFlashMessage(): ?string {
        $msg = $_SESSION['flash_msg'] ?? null;
        if ($msg) {
            unset($_SESSION['flash_msg']);
        }
        return $msg;
    }

    /**
     * بررسی اینکه آیا درخواست AJAX است
     */
    protected function isAjax(): bool {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    /**
     * رندر کردن یک View با داده‌های مشخص
     */
    protected function render(string $viewName, array $data = []) {
        extract($data);
        include __DIR__ . "/../Views/{$viewName}.php";
        exit;
    }

    /**
     * آپلود امن فایل تصویر با اعتبارسنجی MIME type و محدودیت حجم
     *
     * @param string $file_input_name نام فیلد فرم
     * @param string $subfolder زیرپوشه ذخیره‌سازی
     * @return string آدرس نسبی فایل ذخیره‌شده یا رشته خالی در صورت خطا
     */
    protected function uploadAndConvertToWebp($file_input_name, $subfolder = 'uploads'): string {
        if (empty($_FILES[$file_input_name]['tmp_name']) || $_FILES[$file_input_name]['error'] !== UPLOAD_ERR_OK) {
            return '';
        }

        // ---- ۱. بررسی محدودیت حجم ----
        $max_size_bytes = (int)(Bootstrap::getConfig('upload.max_size_mb', 5)) * 1024 * 1024;
        if ($_FILES[$file_input_name]['size'] > $max_size_bytes) {
            $this->setFlashMessage('حجم فایل آپلودی بیش از حد مجاز (' . Bootstrap::getConfig('upload.max_size_mb', 5) . ' مگابایت) است.');
            return '';
        }

        // ---- ۲. بررسی فرمت مجاز از تنظیمات ----
        $allowed_extensions = Bootstrap::getConfig('upload.allowed_types', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
        $name = $_FILES[$file_input_name]['name'];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed_extensions, true)) {
            $this->setFlashMessage('فرمت فایل آپلودی مجاز نیست. فرمت‌های مجاز: ' . implode('، ', $allowed_extensions));
            return '';
        }

        // ---- ۳. اعتبارسنجی MIME type واقعی (جلوگیری از Polyglot Attack) ----
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected_mime = $finfo->file($_FILES[$file_input_name]['tmp_name']);
        $expected_mimes = self::$allowedMimeTypes[$ext] ?? [];

        if (empty($expected_mimes) || !in_array($detected_mime, $expected_mimes, true)) {
            $this->setFlashMessage('نوع فایل آپلودی با فرمت ادعایی مطابقت ندارد. لطفاً یک تصویر واقعی ارسال کنید.');
            return '';
        }

        // ---- ۴. بررسی عدم وجود کد PHP در فایل ----
        $file_content = file_get_contents($_FILES[$file_input_name]['tmp_name'], false, null, 0, 1024);
        if (preg_match('/<\?php|<\?=/i', $file_content)) {
            $this->setFlashMessage('فایل آپلودی حاوی کد اجرایی است و رد شد.');
            return '';
        }

        // ---- ۵. ساخت پوشه و نام تصادفی ----
        $target_dir = rtrim((string)\WHCM\Core\Bootstrap::getConfig('paths.public_assets_path', __DIR__ . '/../../public/assets'), '/\\') . '/' . $subfolder . '/';
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0755, true);
        }

        $filename = bin2hex(random_bytes(8)) . '.webp';
        $target_file = $target_dir . $filename;

        // ---- ۶. تبدیل به WebP ----
        $image = null;
        $tmp = $_FILES[$file_input_name]['tmp_name'];

        if ($detected_mime === 'image/jpeg') {
            $image = @imagecreatefromjpeg($tmp);
        } elseif ($detected_mime === 'image/png') {
            $image = @imagecreatefrompng($tmp);
        } elseif ($detected_mime === 'image/gif') {
            $image = @imagecreatefromgif($tmp);
        } elseif ($detected_mime === 'image/webp') {
            $image = @imagecreatefromwebp($tmp);
        }

        if (!$image) {
            $this->setFlashMessage('خطا در پردازش تصویر آپلودی.');
            return '';
        }

        imagewebp($image, $target_file, 80);
        imagedestroy($image);

        // ---- ۷. مجدداً MIME نهایی را بررسی کن ----
        $final_mime = $finfo->file($target_file);
        if ($final_mime !== 'image/webp') {
            @unlink($target_file);
            $this->setFlashMessage('خطا در تبدیل تصویر.');
            return '';
        }

        $assets_url = Bootstrap::getAssetsUrl();
        return rtrim($assets_url, '/') . '/' . $subfolder . '/' . $filename;
    }

    /**
     * تبدیل تقویم جلالی به میلادی
     */
    protected static function jalaliToGregorian($jy, $jm, $jd) {
        $jy = (int)$jy - 979;
        $jm = (int)$jm - 1;
        $jd = (int)$jd - 1;

        $jy_day = 365 * $jy + (int)($jy / 33) * 8 + (int)(($jy % 33 + 3) / 4);
        for ($i = 0; $i < $jm; ++$i) {
            $jy_day += ($i < 6) ? 31 : 30;
        }

        $g_day = $jy_day + $jd + 79;
        $gy = 1600 + 400 * (int)($g_day / 146097);
        $g_day %= 146097;

        $leap = 1;
        if ($g_day >= 36525) {
            $g_day--;
            $gy += 100 * (int)($g_day / 36524);
            $g_day %= 36524;
            if ($g_day >= 365) {
                $g_day++;
            } else {
                $leap = 0;
            }
        }

        $gy += 4 * (int)($g_day / 1461);
        $g_day %= 1461;

        if ($g_day >= 366) {
            $leap = 0;
            $g_day--;
            $gy += (int)($g_day / 365);
            $g_day %= 365;
        }

        $g_m_d = [0, 31, 28 + $leap, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $gm = 1;
        for ($i = 1; $i <= 12; ++$i) {
            if ($g_day < $g_m_d[$i]) {
                $gm = $i;
                break;
            }
            $g_day -= $g_m_d[$i];
        }
        $gd = $g_day + 1;

        return ['year' => $gy, 'month' => $gm, 'day' => $gd];
    }

    /**
     * ذخیره تنظیمات با الگوی portable (سازگار با SQLite و MySQL)
     */
    protected function saveSetting(int $tenant_id, string $key, string $value) {
        $db = Bootstrap::getDB();
        $stmt = $db->prepare("SELECT id FROM settings WHERE tenant_id = ? AND key_name = ? LIMIT 1");
        $stmt->execute([$tenant_id, $key]);
        if ($stmt->fetch()) {
            $stmt = $db->prepare("UPDATE settings SET key_value = ? WHERE tenant_id = ? AND key_name = ?");
            $stmt->execute([$value, $tenant_id, $key]);
        } else {
            $stmt = $db->prepare("INSERT INTO settings (tenant_id, key_name, key_value) VALUES (?, ?, ?)");
            $stmt->execute([$tenant_id, $key, $value]);
        }
    }

    /**
     * ذخیره انبوه تنظیمات (بهینه‌سازی: کاهش تعداد کوئری‌ها)
     *
     * @param int $tenant_id
     * @param array<string, string> $settings آرایه کلید => مقدار
     */
    protected function saveSettingsBatch(int $tenant_id, array $settings): void {
        $db = Bootstrap::getDB();

        // دریافت کلیدهای موجود یکجا
        $stmt = $db->prepare("SELECT key_name FROM settings WHERE tenant_id = ?");
        $stmt->execute([$tenant_id]);
        $existing = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $inserts = [];
        $updates = [];

        foreach ($settings as $key => $val) {
            if ($key === 'ai_api_key') {
                $val = trim((string)$val);
                if ($val === '') {
                    continue;
                }
                $val = SecretStore::encrypt($val);
            }
            if (in_array($key, $existing, true)) {
                $updates[] = [$val, $tenant_id, $key];
            } else {
                $inserts[] = [$tenant_id, $key, $val];
            }
        }

        // اجرای بهینه INSERT‌ها
        if (!empty($inserts)) {
            $stmt = $db->prepare("INSERT INTO settings (tenant_id, key_name, key_value) VALUES (?, ?, ?)");
            foreach ($inserts as $row) {
                $stmt->execute($row);
            }
        }

        // اجرای بهینه UPDATE‌ها
        if (!empty($updates)) {
            $stmt = $db->prepare("UPDATE settings SET key_value = ? WHERE tenant_id = ? AND key_name = ?");
            foreach ($updates as $row) {
                $stmt->execute($row);
            }
        }
    }
}
