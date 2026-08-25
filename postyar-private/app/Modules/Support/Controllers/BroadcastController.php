<?php
namespace WHCM\Modules\Support\Controllers;

use WHCM\Core\Bootstrap;
use WHCM\Core\Csrf;
use WHCM\Domain\Notification;
use WHCM\Domain\TextFormat;
use WHCM\Controllers\BaseController;
use WHCM\Controllers\MainController;

/**
 * کنترلر ماژول Support — اعلان همگانی
 * قدم ۲-ب
 */
class BroadcastController extends BaseController
{
    public function announce()
    {
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/hnnh');
        }
        $title = trim($_POST['title'] ?? '');
        $message = trim($_POST['message'] ?? '');
        if (empty($title) || empty($message)) {
            $this->setFlashMessage('عنوان و متن اعلان الزامی هستند.');
            $this->redirect('/hnnh');
        }
        // ذخیره در تنظیمات همگانی (برای سازگاری با سیستم قدیمی)
        $announcement_data = json_encode([
            'title' => $title,
            'message' => $message,
            'date' => TextFormat::now_jalali()
        ], JSON_UNESCAPED_UNICODE);
        $db = Bootstrap::getDB();
        $stmt = $db->prepare("SELECT id FROM settings WHERE tenant_id = 0 AND key_name = 'global_announcement' LIMIT 1");
        $stmt->execute();
        if ($stmt->fetch()) {
            $stmt = $db->prepare("UPDATE settings SET key_value = ? WHERE tenant_id = 0 AND key_name = 'global_announcement'");
            $stmt->execute([$announcement_data]);
        } else {
            $stmt = $db->prepare("INSERT INTO settings (tenant_id, key_name, key_value) VALUES (0, 'global_announcement', ?)");
            $stmt->execute([$announcement_data]);
        }

        // ایجاد اعلان در جدول notifications برای تمام کاربران
        try {
            // پاک کردن آخرین خوانده‌شده اعلان قبلی تا زنگوله دوباره فعال شود
            $db->exec("DELETE FROM settings WHERE key_name = 'last_read_announcement_id'");
            Notification::broadcast($title, $message, 'announcement', '');
        } catch (\Throwable $e) {
            error_log('[Postyar Broadcast Notification] ' . $e->getMessage());
        }
        // ارسال پوش ناتیفیکیشن همگانی
        try {
            $pushResults = MainController::sendPushBroadcast($title, $message);
            $sentCount = count(array_filter($pushResults, function ($r) { return $r['success']; }));
            if ($sentCount > 0) {
                $this->setFlashMessage('اعلان برای ' . $sentCount . ' کاربر از طریق مرورگر ارسال شد. 📢🔔');
            } else {
                $this->setFlashMessage('اعلان درون‌برنامه‌ای ذخیره شد. (اشتراک پوش فعالی یافت نشد) 📢');
            }
        } catch (\Throwable $e) {
            error_log('[Postyar Broadcast Push] ' . $e->getMessage());
            $this->setFlashMessage('اعلان ذخیره شد اما ارسال پوش با خطا مواجه شد. 📢');
        }
        $this->redirect('/hnnh');
    }
}
