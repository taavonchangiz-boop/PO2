<?php
namespace WHCM\Core;

/**
 * روتر ساده و منعطف جهت هدایت درخواست‌های سامانه
 *
 * @package WHCM\Core
 */
class Router {
    /** @var array */
    private static $routes = [];

    /**
     * ثبت مسیر GET
     */
    public static function get(string $path, $handler) {
        self::$routes['GET'][$path] = $handler;
    }

    /**
     * ثبت مسیر POST
     */
    public static function post(string $path, $handler) {
        self::$routes['POST'][$path] = $handler;
    }

    /**
     * هدایت درخواست جاری به کنترلر مربوطه
     */
    public static function dispatch() {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        
        // استخراج مسیر خام (حذف پارامترهای کوئری)
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        
        // سازگاری با ساب‌فولدرها
        $script_name = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        if ($script_name !== '/' && strpos($uri, $script_name) === 0) {
            $uri = substr($uri, strlen($script_name));
        }
        $uri = '/' . trim($uri, '/');

        // بررسی وجود پارامتر جایگزین در صورت نیاز (مثلا ?route=/dashboard)
        if (isset($_GET['route'])) {
            $uri = '/' . trim($_GET['route'], '/');
        }

        // جستجو در مسیرها
        $handler = self::$routes[$method][$uri] ?? null;

        if ($handler === null) {
            // جستجوی مسیرهای پویا با پارامتر عددی (مثلا /post/edit/5)
            foreach (self::$routes[$method] ?? [] as $route_path => $route_handler) {
                $pattern = preg_replace('/\{[a-zA-Z0-9_]+\}/', '([0-9]+)', $route_path);
                $pattern = '#^' . $pattern . '$#';
                if (preg_match($pattern, $uri, $matches)) {
                    array_shift($matches); // حذف تطابق کامل
                    return self::execute($route_handler, $matches);
                }
            }

            // جستجوی مسیرهای پویا با پارامتر الفبایی-عددی (مثلا /go/ABC123)
            foreach (self::$routes[$method] ?? [] as $route_path => $route_handler) {
                $pattern = preg_replace('/\{[a-zA-Z0-9_]+\}/', '([a-zA-Z0-9]+)', $route_path);
                $pattern = '#^' . $pattern . '$#';
                if (preg_match($pattern, $uri, $matches)) {
                    array_shift($matches);
                    return self::execute($route_handler, $matches);
                }
            }

            // ۴۰۴ - پیدا نشد
            self::abort(404, 'صفحه مورد نظر یافت نشد.');
        }

        return self::execute($handler);
    }

    /**
     * اجرای هندلر مربوطه
     */
    private static function execute($handler, array $params = []) {
        try {
            if (is_callable($handler)) {
                return call_user_func_array($handler, $params);
            }

            if (is_string($handler)) {
                // قالب: "ControllerName@method"
                $parts = explode('@', $handler);
                $controllerName = $parts[0];
                $method = $parts[1] ?? '';
                $fullClass = "WHCM\\Controllers\\" . $controllerName;

                if (class_exists($fullClass)) {
                    $controller = new $fullClass();
                    if (method_exists($controller, $method)) {
                        return call_user_func_array([$controller, $method], $params);
                    }
                }
            }

            self::abort(500, 'سیستم مسیردهی با خطا مواجه شد.');
        } catch (\Throwable $e) {
            $errorDetail = get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
            error_log('[Postyar Router] FATAL: ' . $errorDetail . ' | Trace: ' . $e->getTraceAsString());

            // ذخیره خطای آخر در فایل برای دیباگ
            try {
                $logDir = __DIR__ . '/../../storage/logs/';
                if (!is_dir($logDir)) {
                    @mkdir($logDir, 0755, true);
                }
                @file_put_contents($logDir . 'last_error.txt',
                    date('Y-m-d H:i:s') . "\n" .
                    $errorDetail . "\n\n" .
                    $e->getTraceAsString()
                );
            } catch (\Throwable $ignored) {}

            // نمایش خطای مناسب بر اساس محیط
            $env = \WHCM\Core\Bootstrap::getConfig('app.env', 'production');
            if ($env === 'development') {
                self::abort(500, $errorDetail);
            } else {
                self::abort(500, 'خطای داخلی سرور. لطفاً چند لحظه بعد دوباره تلاش کنید. شناسه خطا: ' . substr(md5($errorDetail), 0, 8));
            }
        }
    }

    /**
     * نمایش خطای سرور
     */
    public static function abort(int $code, string $message = '') {
        http_response_code($code);
        
        // اگر درخواست AJAX باشد، پاسخ JSON برمی‌گردانیم
        $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);

        if ($is_ajax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => $message ?: 'خطایی رخ داده است.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // نمایش نمای خطا به زبان شیرین فارسی
        include __DIR__ . '/../Views/errors.php';
        exit;
    }
}
