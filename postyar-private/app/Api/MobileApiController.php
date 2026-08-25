<?php
namespace WHCM\Api;

use WHCM\Core\Bootstrap;

/**
 * کنترلر پایه برای تمام کنترلرهای API
 *
 * متدهای کمکی مشترک بین تمام API Controllerها
 *
 * @package WHCM\Api
 */
class MobileApiController {

    /**
     * دریافت ورودی JSON بدنه درخواست
     */
    protected function input(): array {
        return MobileApiRouter::jsonInput();
    }

    /**
     * دریافت یک فیلد خاص از ورودی
     */
    protected function get(string $key, mixed $default = null): mixed {
        $data = $this->input();
        return $data[$key] ?? $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    /**
     * دریافت دیتابیس
     */
    protected function db(): \PDO {
        return Bootstrap::getDB();
    }

    /**
     * دریافت کاربر فعلی
     */
    protected function user(): ?array {
        return MobileApiRouter::currentUser();
    }

    /**
     * دریافت ID کاربر فعلی
     */
    protected function userId(): int {
        $user = $this->user();
        if (!$user) {
            MobileApiResponse::unauthorized();
            exit;
        }
        return $user['id'];
    }

    /**
     * بررسی دسترسی ادمین
     */
    protected function requireAdmin(): void {
        $user = $this->user();
        if (!$user || ($user['role'] !== 'superadmin' && $user['role'] !== 'support_agent')) {
            MobileApiResponse::forbidden();
            exit;
        }
    }

    /**
     * بررسی دسترسی سوپر ادمین
     */
    protected function requireSuperAdmin(): void {
        $user = $this->user();
        if (!$user || $user['role'] !== 'superadmin') {
            MobileApiResponse::forbidden();
            exit;
        }
    }

    /**
     * اعتبارسنجی ساده فیلدها
     *
     * @return array لیست خطاها (خالی = معتبر)
     */
    protected function validate(array $rules, array $data): array {
        $errors = [];
        foreach ($rules as $field => $fieldRules) {
            $ruleList = explode('|', $fieldRules);
            $value = $data[$field] ?? null;

            foreach ($ruleList as $rule) {
                if ($rule === 'required' && (empty($value) && $value !== '0' && $value !== 0)) {
                    $errors[$field] = "فیلد {$field} الزامی است.";
                    break;
                }
                if ($rule === 'email' && !empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$field] = "فرمت ایمیل نامعتبر است.";
                }
                if (strpos($rule, 'min:') === 0 && !empty($value)) {
                    $min = (int)substr($rule, 4);
                    if (mb_strlen((string)$value) < $min) {
                        $errors[$field] = "حداقل {$min} کاراکتر وارد کنید.";
                    }
                }
                if (strpos($rule, 'max:') === 0 && !empty($value)) {
                    $max = (int)substr($rule, 4);
                    if (mb_strlen((string)$value) > $max) {
                        $errors[$field] = "حداکثر {$max} کاراکتر مجاز است.";
                    }
                }
            }
        }
        return $errors;
    }

    /**
     * آپلود و تبدیل تصویر به WebP (استفاده از منطق BaseController)
     */
    protected function uploadImage(string $inputName, string $subfolder = 'uploads'): ?string {
        if (!isset($_FILES[$inputName]) || $_FILES[$inputName]['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        $file = $_FILES[$inputName];
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $maxSize = 5 * 1024 * 1024; // 5MB

        if (!in_array($file['type'], $allowed)) {
            MobileApiResponse::error('فرمت فایل مجاز نیست. فقط JPG, PNG, GIF, WebP مجاز است.', 400);
            return null;
        }

        if ($file['size'] > $maxSize) {
            MobileApiResponse::error('حجم فایل بیش از ۵ مگابایت است.', 400);
            return null;
        }

        // خواندن و تبدیل به WebP
        $imageData = file_get_contents($file['tmp_name']);
        $image = imagecreatefromstring($imageData);
        if (!$image) {
            return null;
        }

        $dir = rtrim((string)\WHCM\Core\Bootstrap::getConfig('paths.public_assets_path', __DIR__ . '/../../public/assets'), '/\\') . '/' . $subfolder;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = 'api_' . bin2hex(random_bytes(24)) . '.webp';
        $filepath = $dir . '/' . $filename;

        imagewebp($image, $filepath, 80);
        imagedestroy($image);

        return '/assets/' . $subfolder . '/' . $filename;
    }
}
