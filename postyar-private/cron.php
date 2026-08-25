<?php
/**
 * اسکریپت Cron Job پُست‌یار
 *
 * این فایل مستقیماً از طریق Cron اجرا می‌شود و شامل:
 *   ۱. ارسال خودکار نرخ طلا (GoldTicker)
 *   ۲. Polling پیام‌های دریافتی و پاسخگوی خودکار (Inbox)
 *   ۳. پردازش و ارسال پست‌های زمان‌بندی‌شده (ScheduledPost)
 *   ۴. مدیریت انقضای اشتراک‌ها و ارسال یادآوری (SubscriptionManager)
 *   ۵. پاکسازی کدهای تایید منقضی‌شده (Verification Codes)
 *   ۶. پاکسازی فایل‌های قدیمی آپلود شده (Disk Cleanup)
 *
 * ⚠️  این فایل نباید از طریق وب قابل دسترسی باشد.
 *      فایل .htaccess آن را محافظت می‌کند.
 *
 * ⚙️  تنظیم در cPanel > Cron Jobs:
 *      * * * * * /usr/local/bin/php /home/asovinir/public_html/cron.php >> /dev/null 2>&1
 *      یا دقیق‌تر (هر دقیقه):
 *      * * * * * /usr/local/bin/php -f /home/asovinir/public_html/cron.php >/dev/null 2>&1
 *
 * @package WHCM_SaaS
 */

// فقط از طریق CLI اجرا شود
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('دسترسی غیرمجاز.');
}

// Global single-host cron lease. cPanel can occasionally overlap minute-based jobs;
// without a process lock every polling/scheduled task could execute twice.
$cronLockPath = __DIR__ . '/storage/cron.lock';
$cronLock = @fopen($cronLockPath, 'c');
if ($cronLock === false || !flock($cronLock, LOCK_EX | LOCK_NB)) {
    if (is_resource($cronLock)) fclose($cronLock);
    exit(0);
}
register_shutdown_function(static function () use ($cronLock): void {
    if (is_resource($cronLock)) {
        @flock($cronLock, LOCK_UN);
        @fclose($cronLock);
    }
});

// بارگذاری Bootstrap
require_once __DIR__ . '/app/Core/Bootstrap.php';

use WHCM\Core\Bootstrap;
use WHCM\Domain\GoldTicker;
use WHCM\Domain\Inbox;
use WHCM\Domain\ScheduledPost;
use WHCM\Domain\SubscriptionManager;

Bootstrap::run();

// ---- ۱. ارسال خودکار نرخ طلا ----
try {
    GoldTicker::tickAll();
} catch (\Throwable $e) {
    error_log('[Postyar Cron] GoldTicker error: ' . $e->getMessage());
}

// ---- ۲. Polling پیام‌های دریافتی و پاسخگوی خودکار ----
try {
    Inbox::pollAllActive();
} catch (\Throwable $e) {
    error_log('[Postyar Cron] Inbox Polling error: ' . $e->getMessage());
}

// ---- ۳. پردازش پست‌های زمان‌بندی‌شده ----
try {
    $processed = ScheduledPost::processAll();
    if ($processed > 0) {
        error_log('[Postyar Cron] Processed ' . $processed . ' scheduled posts.');
    }
} catch (\Throwable $e) {
    error_log('[Postyar Cron] ScheduledPost error: ' . $e->getMessage());
}

// ---- ۴. مدیریت انقضای اشتراک‌ها و ارسال یادآوری ----
try {
    $result = SubscriptionManager::processExpiries();
    if ($result['expired'] > 0 || $result['reminded'] > 0) {
        error_log('[Postyar Cron] Subscriptions: ' . $result['expired'] . ' expired, ' . $result['reminded'] . ' reminded.');
    }
} catch (\Throwable $e) {
    error_log('[Postyar Cron] SubscriptionManager error: ' . $e->getMessage());
}

// ---- ۵. پاکسازی کدهای تایید منقضی‌شده ----
try {
    $cleaned_codes = SubscriptionManager::cleanupVerificationCodes();
    if ($cleaned_codes > 0) {
        error_log('[Postyar Cron] Cleaned ' . $cleaned_codes . ' expired verification codes.');
    }
} catch (\Throwable $e) {
    error_log('[Postyar Cron] Verification code cleanup error: ' . $e->getMessage());
}

// ---- ۶. پاکسازی فایل‌های قدیمی آپلود شده ----
try {
    $cleaned_files = Bootstrap::cleanupOldUploads(30);
    if ($cleaned_files > 0) {
        error_log('[Postyar Cron] Cleaned ' . $cleaned_files . ' old upload files.');
    }
} catch (\Throwable $e) {
    error_log('[Postyar Cron] Disk cleanup error: ' . $e->getMessage());
}
