<?php
namespace WHCM\Api;

use WHCM\Core\RateLimit;

final class MobileApiRouter
{
    private const MAX_BODY_BYTES = 2097152;
    private static array $routes = [];
    private static array $globalMiddleware = [];

    public static function get(string $path, string $handler, array $middleware = []): void { self::register('GET', $path, $handler, $middleware); }
    public static function post(string $path, string $handler, array $middleware = []): void { self::register('POST', $path, $handler, $middleware); }
    public static function put(string $path, string $handler, array $middleware = []): void { self::register('PUT', $path, $handler, $middleware); }
    public static function delete(string $path, string $handler, array $middleware = []): void { self::register('DELETE', $path, $handler, $middleware); }

    private static function register(string $verb, string $path, string $handler, array $middleware): void
    {
        if ($path === '' || $path[0] !== '/') {
            throw new \InvalidArgumentException('API route path must start with /.');
        }
        self::$routes[$verb][] = compact('path', 'handler', 'middleware');
    }

    public static function middleware(callable $middleware): void { self::$globalMiddleware[] = $middleware; }

    public static function dispatch(string $method, string $uri): void
    {
        self::sendSecurityHeaders();
        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > self::MAX_BODY_BYTES) { MobileApiResponse::error('حجم درخواست بیش از حد مجاز است.', 413); return; }
        $pathOnly = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = '/' . ltrim((string)preg_replace('#^/api/v1/?#', '', $pathOnly), '/');
        $routeList = self::$routes[strtoupper($method)] ?? [];

        foreach ($routeList as $route) {
            if (!preg_match(self::buildPattern($route['path']), $path, $matches)) { continue; }
            if (!self::runGlobalMiddleware() || !self::runRouteMiddleware($route['middleware'])) { return; }
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            self::callHandler($route['handler'], array_values($params));
            return;
        }
        MobileApiResponse::notFound('مسیر API یافت نشد.');
    }

    private static function runGlobalMiddleware(): bool
    {
        foreach (self::$globalMiddleware as $middleware) {
            try { if ($middleware() === false) return false; }
            catch (\Throwable $e) { error_log('API global middleware failure: ' . $e->getMessage()); MobileApiResponse::serverError('خطای داخلی سرور.'); return false; }
        }
        return true;
    }

    private static function runRouteMiddleware(array $middleware): bool
    {
        foreach ($middleware as $name) {
            try { if (!self::runMiddleware((string)$name)) return false; }
            catch (\Throwable $e) { error_log('API middleware failure [' . (string)$name . ']: ' . $e->getMessage()); MobileApiResponse::serverError('خطای داخلی سرور.'); return false; }
        }
        return true;
    }

    private static function buildPattern(string $path): string
    {
        $escaped = preg_quote($path, '#');
        $pattern = preg_replace('/\\\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\\\}/', '(?P<$1>[^/]+)', $escaped);
        return '#^' . $pattern . '$#D';
    }

    private static function runMiddleware(string $name): bool
    {
        switch ($name) {
            case 'auth':
                $user = MobileApiAuth::validate();
                if (!$user) { MobileApiResponse::unauthorized(); return false; }
                MobileApiAuth::injectSession((int)$user['id']); return true;
            case 'admin':
                $user = MobileApiAuth::validate();
                if (!$user || !in_array($user['role'] ?? '', ['superadmin', 'support_agent'], true)) { MobileApiResponse::forbidden(); return false; }
                MobileApiAuth::injectSession((int)$user['id']); return true;
            case 'superadmin':
                $user = MobileApiAuth::validate();
                if (!$user || ($user['role'] ?? '') !== 'superadmin') { MobileApiResponse::forbidden(); return false; }
                MobileApiAuth::injectSession((int)$user['id']); return true;
            case 'rate_limit':
                if (!RateLimit::consume('api_general', 120, 60)) { MobileApiResponse::tooManyRequests(); return false; }
                return true;
            default:
                // Fail closed: an unknown middleware name must never silently expose a route.
                error_log('Unknown API middleware: ' . $name);
                MobileApiResponse::serverError('پیکربندی مسیر نامعتبر است.');
                return false;
        }
    }

    private static function callHandler(string $handler, array $params): void
    {
        try {
            if (str_contains($handler, '@') || str_contains($handler, '::')) {
                $sep = str_contains($handler, '@') ? '@' : '::';
                [$class, $method] = explode($sep, $handler, 2);
                $fullClass = str_starts_with($class, '\\') ? $class : '\\WHCM\\Api\\Controllers\\' . $class;
                if (!class_exists($fullClass) || !method_exists($fullClass, $method) || !is_callable([$fullClass, $method])) { MobileApiResponse::serverError('خطای داخلی سرور.'); return; }
                $instance = new $fullClass();
                $instance->$method(...$params);
                return;
            }
            MobileApiResponse::serverError('خطای داخلی سرور.');
        } catch (\Throwable $e) {
            error_log('API handler failure [' . $handler . ']: ' . $e->getMessage());
            MobileApiResponse::serverError('خطای داخلی سرور.');
        }
    }

    public static function jsonInput(): array
    {
        static $decoded = null;
        if ($decoded !== null) return $decoded;
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') return $decoded = [];
        try { $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { MobileApiResponse::error('بدنه JSON نامعتبر است.', 400); return $decoded = []; }
        return $decoded = is_array($data) ? $data : [];
    }

    public static function input(string $key, mixed $default = null): mixed
    {
        $json = self::jsonInput();
        return array_key_exists($key, $json) ? $json[$key] : ($_POST[$key] ?? $default);
    }

    public static function currentUser(): ?array { return MobileApiAuth::validate(); }
    public static function currentUserId(): ?int { $user = self::currentUser(); return $user ? (int)$user['id'] : null; }

    private static function sendSecurityHeaders(): void
    {
        if (headers_sent()) return;
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header('Cache-Control: no-store, private');
    }
}
