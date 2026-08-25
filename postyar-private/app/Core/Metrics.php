<?php
namespace WHCM\Core;

final class Metrics
{
    private const KNOWN = [
        'http_requests_total', 'http_2xx_total', 'http_3xx_total',
        'http_4xx_total', 'http_5xx_total', 'http_slow_requests_total'
    ];

    public static function increment(string $name, int $value = 1, array $labels = []): void
    {
        $suffix = '';
        if ($labels) {
            ksort($labels);
            $pairs = [];
            foreach ($labels as $k => $v) {
                $k = preg_replace('/[^a-zA-Z0-9_]/', '_', (string)$k);
                $v = preg_replace('/[^a-zA-Z0-9_]/', '_', (string)$v);
                $pairs[] = $k . '_' . $v;
            }
            $suffix = '_' . implode('_', $pairs);
        }
        Cache::increment('metrics:' . $name . $suffix, $value);
    }

    public static function observeRequest(int $status, float $ms): void
    {
        self::increment('http_requests_total');
        $bucket = intdiv(max(100, min(599, $status)), 100);
        if ($bucket === 2) self::increment('http_2xx_total');
        elseif ($bucket === 3) self::increment('http_3xx_total');
        elseif ($bucket === 4) self::increment('http_4xx_total');
        elseif ($bucket === 5) self::increment('http_5xx_total');
        if ($ms >= 1000) self::increment('http_slow_requests_total');
    }

    public static function render(): string
    {
        $lines = [];
        foreach (self::KNOWN as $name) {
            $value = Cache::get('metrics:' . $name);
            if ($value === null) $value = 0;
            $lines[] = $name . ' ' . (int)$value;
        }
        return implode("\n", $lines) . "\n";
    }
}
