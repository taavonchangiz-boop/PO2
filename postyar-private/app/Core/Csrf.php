<?php
namespace WHCM\Core;

/**
 * مدیریت توکن‌های ضد CSRF جهت ارتقای امنیت فرم‌ها
 *
 * @package WHCM\Core
 */
class Csrf {
    /**
     * تولید یا بازیابی توکن فعلی
     */
    public static function getToken(): string {
        $token = Session::get('csrf_token');
        if (!$token) {
            $token = bin2hex(random_bytes(32));
            Session::set('csrf_token', $token);
        }
        return $token;
    }

    /**
     * اعتبارسنجی توکن ارسال شده
     */
    /** Rotate the token after an authentication boundary. */
    public static function rotate(): string {
        $token = bin2hex(random_bytes(32));
        Session::set('csrf_token', $token);
        return $token;
    }

    public static function validate(?string $token): bool {
        if ($token === null) {
            return false;
        }
        $stored = Session::get('csrf_token');
        if (!$stored) {
            return false;
        }
        // مقایسه زمان ثابت جهت جلوگیری از حملات Timing Attack
        return hash_equals($stored, $token);
    }

    /**
     * خروجی فیلد input پنهان برای فرم‌ها
     */
    public static function field(): string {
        $token = self::getToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
}
