<?php
namespace WHCM\Core;

final class RequestContext
{
    private static ?string $id = null;
    private static float $startedAt = 0.0;

    public static function start(): void
    {
        self::$startedAt = microtime(true);
        $candidate = (string)($_SERVER['HTTP_X_REQUEST_ID'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $candidate)) {
            $candidate = bin2hex(random_bytes(16));
        }
        self::$id = $candidate;
        header('X-Request-ID: ' . self::$id);
    }

    public static function id(): string
    {
        if (self::$id === null) self::start();
        return self::$id;
    }

    public static function elapsedMs(): float
    {
        return self::$startedAt > 0 ? round((microtime(true) - self::$startedAt) * 1000, 2) : 0.0;
    }
}
