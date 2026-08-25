<?php
namespace WHCM\Api;

use WHCM\Core\Bootstrap;
use WHCM\Core\RateLimit;

/**
 * مسیردهی اختصاصی API موبایل
 *
 * تمام مسیرهای /api/v1/... در این کلاس ثبت می‌شوند.
 *
 * @package WHCM\Api
 */
class MobileApiRouter {

    private static array $routes = [];
    private static array $globalMiddleware = [];

    public static function get(string $path, string $handler, array $middleware = []): void {
        self::$routes['GET'][] = ['path' => $path, 'handler' => $handler, 'middleware' => $middleware];
    }

    public static function post(string $path, string $handler, array $middleware = []): void {
        self::$routes['POST'][] = ['path' => $path, 'handler' => $handler, 'middleware' => $middleware];
    }

    public static function put(string $path, string $handler, array $middleware = []): void {
        self::$routes['PUT'][] = ['path' => $path, 'handler' => $handler, 'middleware' => $middleware];
    }

    public static function delete(string $path, string $handler, array $middleware = []): void {
        self::$routes['DELETE'][] = ['path' => $path, 'handler' => $handler, 'middleware' => $middleware];
    }

    public static function middleware(callable $middleware): void {
        self::$globalMiddleware[] = $middleware;
    }

    public static function dispatch(string $method, string $uri): void {

        // Hard upper bound prevents accidental/hostile oversized JSON bodies.
        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > 2 * 1024 * 1024) {
            MobileApiResponse::error('حجم درخواست بیش از حد مجاز است.', 413);
        }

        $path = preg_replace('#^/api/v1/?#', '', $uri);
        $path = '/' . $path;

        $method = strtoupper($method);
        $routeList = self::$routes[$method] ?? [];

        foreach ($routeList as $route) {

            $pattern = self::buildPattern($route['path']);

            if (preg_match($pattern, $path, $matches)) {

                foreach (self::$globalMiddleware as $mw) {
                    try {
                        $result = $mw();

                        if ($result === false) {
                            return;
                        }

                    } catch (\Throwable $e) {

                        error_log(
                            "API Global Middleware Error: " . $e->getMessage()
                        );

                        MobileApiResponse::serverError(
                            'خطای داخلی سرور.'
                        );

                        return;
                    }
                }


                foreach ($route['middleware'] as $mwName) {

                    try {

                        $result = self::runMiddleware($mwName);

                        if ($result === false) {
                            return;
                        }

                    } catch (\Throwable $e) {

                        error_log(
                            "API Middleware Error [" . $mwName . "]: " . $e->getMessage()
                        );

                        MobileApiResponse::serverError(
                            'خطای داخلی سرور.'
                        );

                        return;
                    }
                }


                $params = array_filter(
                    $matches,
                    'is_string',
                    ARRAY_FILTER_USE_KEY
                );


                self::callHandler(
                    $route['handler'],
                    $params
                );

                return;
            }
        }

        MobileApiResponse::notFound('مسیر API یافت نشد.');
    }


    private static function buildPattern(string $path): string {

        $pattern = preg_replace(
            '/\{([a-zA-Z_]+)\}/',
            '(?P<$1>[^/]+)',
            $path
        );

        return '#^' . $pattern . '$#';
    }


    private static function runMiddleware(string $name): bool {

        switch ($name) {

            case 'auth':

                $user = MobileApiAuth::validate();

                if (!$user) {

                    MobileApiResponse::unauthorized();

                    return false;
                }

                MobileApiAuth::injectSession(
                    $user['id']
                );

                return true;


            case 'admin':

                $user = MobileApiAuth::validate();

                if (
                    !$user ||
                    (
                        $user['role'] !== 'superadmin' &&
                        $user['role'] !== 'support_agent'
                    )
                ) {

                    MobileApiResponse::forbidden();

                    return false;
                }

                MobileApiAuth::injectSession(
                    $user['id']
                );

                return true;


            case 'superadmin':

                $user = MobileApiAuth::validate();

                if (
                    !$user ||
                    $user['role'] !== 'superadmin'
                ) {

                    MobileApiResponse::forbidden();

                    return false;
                }

                MobileApiAuth::injectSession(
                    $user['id']
                );

                return true;


            case 'rate_limit':

                if (
                    !RateLimit::consume(
                        'api_general',
                        120,
                        60
                    )
                ) {

                    MobileApiResponse::tooManyRequests();

                    return false;
                }

                return true;


            default:

                return true;
        }
    }


    private static function callHandler(string $handler, array $params): void {

        try {

            if (
                strpos($handler, '@') !== false ||
                strpos($handler, '::') !== false
            ) {

                $sep = strpos($handler, '@') !== false
                    ? '@'
                    : '::';


                [$class, $method] = explode(
                    $sep,
                    $handler,
                    2
                );


                $fullClass = strpos($class, '\\') === 0
                    ? $class
                    : '\\WHCM\\Api\\Controllers\\' . $class;


                if (!class_exists($fullClass)) {

                    MobileApiResponse::serverError(
                        'کنترلر ' . $class . ' یافت نشد.'
                    );

                    return;
                }


                $instance = new $fullClass();


                if (!method_exists($instance, $method)) {

                    MobileApiResponse::serverError(
                        'متد ' . $method . ' در کنترلر ' . $class . ' وجود ندارد.'
                    );

                    return;
                }


                $instance->$method(
                    ...array_values($params)
                );


            } else {


                if (is_callable($handler)) {

                    $handler(
                        ...array_values($params)
                    );

                } else {

                    MobileApiResponse::serverError(
                        'Handler نامعتبر است.'
                    );
                }
            }


        } catch (\Throwable $e) {

            error_log(
                "API Handler Error [" . $handler . "]: " . $e->getMessage()
            );


            MobileApiResponse::serverError(
                'خطای داخلی سرور.'
            );
        }
    }


    public static function jsonInput(): array {

        $raw = file_get_contents(
            'php://input'
        );

        $data = json_decode(
            $raw,
            true
        );

        return is_array($data)
            ? $data
            : [];
    }


    public static function input(
        string $key,
        mixed $default = null
    ): mixed {

        $json = self::jsonInput();

        if (isset($json[$key])) {

            return $json[$key];
        }

        return $_POST[$key] ?? $default;
    }


    public static function currentUser(): ?array {

        return MobileApiAuth::validate();
    }


    public static function currentUserId(): ?int {

        $user = self::currentUser();

        return $user
            ? $user['id']
            : null;
    }
}