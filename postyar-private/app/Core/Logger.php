<?php
namespace WHCM\Core;

final class Logger
{
    public static function info(string $message, array $context = []): void { self::write('info', $message, $context); }
    public static function warning(string $message, array $context = []): void { self::write('warning', $message, $context); }
    public static function error(string $message, array $context = []): void { self::write('error', $message, $context); }

    private static function write(string $level, string $message, array $context): void
    {
        $record = [
            'ts' => gmdate('c'),
            'level' => $level,
            'message' => $message,
            'request_id' => RequestContext::id(),
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'path' => parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '',
        ];
        if ($context) $record['context'] = self::redact($context);
        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        error_log('[Postyar] ' . $line);
        $dir = __DIR__ . '/../../storage/logs';
        if (is_dir($dir) || @mkdir($dir, 0750, true)) {
            @file_put_contents($dir . '/app.jsonl', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }

    private static function redact(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        $secretKeys = ['password','pass','token','api_key','secret','private_key','authorization','cookie','salt'];
        $out = [];
        foreach ($value as $k => $v) {
            $lk = strtolower((string)$k);
            $out[$k] = in_array($lk, $secretKeys, true) ? '[REDACTED]' : (is_array($v) ? self::redact($v) : $v);
        }
        return $out;
    }
}
