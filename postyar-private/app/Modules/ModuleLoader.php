<?php
namespace WHCM\Modules;

/**
 * بارگذار ماژولار — قدم ۱
 * بدون تغییر رفتار، فقط اسکلت را لود می‌کند
 * در قدم ۲ مسیرها از هر ماژول خوانده می‌شوند
 */
class ModuleLoader
{
    public static function load(): void
    {
        $base = __DIR__;
        $modules = glob($base . '/*/module.json');
        if (!$modules) return;

        foreach ($modules as $jsonFile) {
            $dir = dirname($jsonFile);
            $cfg = @json_decode(@file_get_contents($jsonFile), true);
            if (empty($cfg['enabled'])) continue;

            $routes = $dir . '/Routes.php';
            if (file_exists($routes)) {
                // در قدم ۱ فایل‌ها خالی هستند — صرفاً include می‌شوند
                // در قدم ۲ هر Routes.php مسیرهای خودش را ثبت می‌کند
                require_once $routes;
            }
        }
    }

    /** لیست ماژول‌های فعال برای نمایش در پنل مدیریت */
    public static function list(): array
    {
        $out = [];
        foreach (glob(__DIR__ . '/*/module.json') as $f) {
            $cfg = json_decode(file_get_contents($f), true);
            $out[] = $cfg;
        }
        return $out;
    }
}
