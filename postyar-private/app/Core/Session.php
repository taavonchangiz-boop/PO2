<?php
namespace WHCM\Core;

/**
 * مدیریت سشن‌های امن پلتفرم
 *
 * @package WHCM\Core
 */
class Session {
    /**
     * شروع سشن با تنظیمات امنیتی بالا
     */
    public static function start() {
        if (session_status() === PHP_SESSION_NONE) {
            // تنظیم کوکی‌های سشن به صورت کاملاً امن
            ini_set('session.cookie_httponly', 1);
            ini_set('session.use_only_cookies', 1);
            
            // اگر ارتباط HTTPS باشد، کوکی سشن هم امن خواهد بود
            $is_secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
            if ($is_secure) {
                ini_set('session.cookie_secure', 1);
            }

            // تنظیم SameSite به Strict یا Lax جهت جلوگیری از CSRF روی سشن کوکی (سازگار با تمامی نسخه‌های PHP قدیم و جدید)
            if (PHP_VERSION_ID >= 70300) {
                session_set_cookie_params([
                    'lifetime' => 0, // تا بستن مرورگر
                    'path' => '/',
                    'domain' => '',
                    'secure' => $is_secure,
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);
            } else {
                session_set_cookie_params(0, '/; SameSite=Lax', '', $is_secure, true);
            }

            session_start();
        }
    }

    /**
     * ست کردن مقدار در سشن
     */
    public static function set(string $key, $value) {
        $_SESSION[$key] = $value;
    }

    /**
     * خواندن مقدار از سشن
     */
    public static function get(string $key, $default = null) {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * حذف یک کلید از سشن
     */
    public static function remove(string $key) {
        if (isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
        }
    }

    /**
     * از بین بردن کل سشن (خروج کاربر)
     */
    public static function destroy() {
        if (session_status() !== PHP_SESSION_NONE) {
            $_SESSION = [];
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params["path"],
                    $params["domain"],
                    $params["secure"],
                    $params["httponly"]
                );
            }
            session_destroy();
        }
    }

    /**
     * بازنشانی شناسه سشن برای جلوگیری از Session Hijacking
     */
    public static function regenerate() {
        if (session_status() !== PHP_SESSION_NONE && !headers_sent()) {
            session_regenerate_id(true);
        }
    }
}
