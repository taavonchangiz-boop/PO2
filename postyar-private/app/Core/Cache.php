<?php
namespace WHCM\Core;

/**
 * Lightweight shared cache facade.
 *
 * Production preference: Redis, then APCu, then request-local memory.
 * The application never treats cache as authoritative state; failures always
 * fall back to the database/source of truth.
 */
class Cache
{
    private static array $local = [];
    private static $redis = null;
    private static bool $redisInitialized = false;

    public static function remember(string $key, int $ttl, callable $resolver): mixed
    {
        $cached = self::get($key);
        if ($cached !== null) return $cached;
        $value = $resolver();
        if ($value !== null) self::set($key, $value, $ttl);
        return $value;
    }

    public static function get(string $key): mixed
    {
        $now = time();
        if (isset(self::$local[$key]) && self::$local[$key]['expires'] >= $now) {
            return self::$local[$key]['value'];
        }
        unset(self::$local[$key]);

        $redis = self::redis();
        if ($redis) {
            try {
                $raw = $redis->get(self::namespaced($key));
                if ($raw !== false) return json_decode($raw, true);
            } catch (\Throwable $e) { /* cache is never authoritative */ }
        }

        if (function_exists('apcu_fetch')) {
            $success = false;
            $value = apcu_fetch(self::namespaced($key), $success);
            if ($success) return $value;
        }
        return null;
    }

    public static function set(string $key, mixed $value, int $ttl = 60): void
    {
        $ttl = max(1, min($ttl, 86400));
        self::$local[$key] = ['value' => $value, 'expires' => time() + $ttl];

        $redis = self::redis();
        if ($redis) {
            try { $redis->setex(self::namespaced($key), $ttl, json_encode($value, JSON_UNESCAPED_UNICODE)); } catch (\Throwable $e) {}
            return;
        }
        if (function_exists('apcu_store')) {
            try { apcu_store(self::namespaced($key), $value, $ttl); } catch (\Throwable $e) {}
        }
    }

    public static function increment(string $key, int $by = 1): int
    {
        $by = max(1, $by);
        $redis = self::redis();
        if ($redis) {
            try { return (int)$redis->incrBy(self::namespaced($key), $by); } catch (\Throwable $e) {}
        }
        if (function_exists('apcu_inc')) {
            $success = false;
            $value = apcu_inc(self::namespaced($key), $by, $success);
            if ($success) return (int)$value;
            try { apcu_add(self::namespaced($key), $by); return (int)apcu_fetch(self::namespaced($key)); } catch (\Throwable $e) {}
        }
        $current = (int)(self::$local[$key]['value'] ?? 0) + $by;
        self::$local[$key] = ['value' => $current, 'expires' => PHP_INT_MAX];
        return $current;
    }

    public static function forget(string $key): void
    {
        unset(self::$local[$key]);
        $redis = self::redis();
        if ($redis) { try { $redis->del(self::namespaced($key)); } catch (\Throwable $e) {} }
        if (function_exists('apcu_delete')) { try { apcu_delete(self::namespaced($key)); } catch (\Throwable $e) {} }
    }

    private static function namespaced(string $key): string
    {
        $prefix = (string)Bootstrap::getConfig('cache.prefix', 'postyar');
        return $prefix . ':' . hash('sha256', $key);
    }

    private static function redis()
    {
        if (self::$redisInitialized) return self::$redis;
        self::$redisInitialized = true;
        if (!class_exists('Redis')) return null;
        if (!(bool)Bootstrap::getConfig('cache.redis.enabled', false)) return null;
        try {
            $r = new \Redis();
            $host = (string)Bootstrap::getConfig('cache.redis.host', '127.0.0.1');
            $port = (int)Bootstrap::getConfig('cache.redis.port', 6379);
            $timeout = (float)Bootstrap::getConfig('cache.redis.timeout', 0.5);
            if (!$r->connect($host, $port, $timeout)) return null;
            $password = Bootstrap::getConfig('cache.redis.password', null);
            if ($password !== null && $password !== '') $r->auth($password);
            $db = (int)Bootstrap::getConfig('cache.redis.database', 0);
            if ($db > 0) $r->select($db);
            self::$redis = $r;
        } catch (\Throwable $e) {
            self::$redis = null;
        }
        return self::$redis;
    }
}
