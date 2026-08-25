<?php
namespace WHCM\Api;

/**
 * پاسخ‌های استاندارد JSON برای API موبایل
 *
 * تمام endpointهای API باید از این کلاس برای ارسال پاسخ استفاده کنند.
 * این کلاس تضمین می‌کند که ساختار JSON همیشه یکنواخت باشد.
 *
 * @package WHCM\Api
 */
class MobileApiResponse {

    /**
     * پاسخ موفقیت‌آمیز با داده
     */
    private static function securityHeaders(): void {
        if (headers_sent()) return;
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
    }

    public static function success(mixed $data = null, string $message = '', int $statusCode = 200): void {
        http_response_code($statusCode);
        self::securityHeaders();
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * پاسخ خطا
     */
    public static function error(string $message = 'خطای ناشناخته', int $statusCode = 400, mixed $errors = null): void {
        http_response_code($statusCode);
        self::securityHeaders();
        $response = [
            'success' => false,
            'message' => $message
        ];
        if ($errors !== null) {
            $response['errors'] = $errors;
        }
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * پاسخ اعتبارسنجی (Validation Error)
     */
    public static function validationError(array $errors, string $message = 'اطلاعات ورودی نامعتبر است'): void {
        self::error($message, 422, $errors);
    }

    /**
     * پاسخ عدم احراز هویت
     */
    public static function unauthorized(string $message = 'لطفاً ابتدا وارد حساب کاربری خود شوید'): void {
        self::error($message, 401);
    }

    /**
     * پاسخ ممنوع (Forbidden)
     */
    public static function forbidden(string $message = 'شما دسترسی لازم برای انجام این عملیات را ندارید'): void {
        self::error($message, 403);
    }

    /**
     * پاسخ یافت نشد
     */
    public static function notFound(string $message = 'منبع مورد نظر یافت نشد'): void {
        self::error($message, 404);
    }

    /**
     * پاسخ تعداد درخواست بیش از حد
     */
    public static function tooManyRequests(string $message = 'تعداد درخواست‌های شما بیش از حد مجاز است. لطفاً稍后 دوباره تلاش کنید.'): void {
        self::error($message, 429);
    }

    /**
     * پاسخ خطای سرور
     */
    public static function serverError(string $message = 'خطای داخلی سرور'): void {
        self::error($message, 500);
    }
}
