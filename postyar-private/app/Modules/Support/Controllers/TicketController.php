<?php
namespace WHCM\Modules\Support\Controllers;

use WHCM\Core\Bootstrap;
use WHCM\Core\Auth;
use WHCM\Core\Csrf;
use WHCM\Domain\TextFormat;
use WHCM\Controllers\BaseController;

/**
 * کنترلر ماژول Support — تیکت‌ها با فایل و ارجاع و بستن همزمان
 * قدم ۲-ب تکمیلی
 */
class TicketController extends BaseController
{
    private function handleAttachment(string $field = 'attachment'): string
    {
        if (empty($_FILES[$field]['tmp_name']) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            return '';
        }
        $tmp = $_FILES[$field]['tmp_name'];
        $name = $_FILES[$field]['name'];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowedMimes = [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/gif' => ['gif'],
            'image/webp' => ['webp'],
            'application/pdf' => ['pdf'],
        ];
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset($allowedMimes[$mime]) || !in_array($ext, $allowedMimes[$mime], true)) {
            return '';
        }
        if ((int)($_FILES[$field]['size'] ?? 0) > 5 * 1024 * 1024) {
            return '';
        }
        $targetDir = rtrim((string)\WHCM\Core\Bootstrap::getConfig('paths.private_storage_path', dirname(__DIR__, 4) . '/storage/private'), '/\\') . '/tickets/';
        if (!file_exists($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        $filename = 'ticket_' . bin2hex(random_bytes(24)) . '.' . $ext;
        $target = $targetDir . $filename;
        // برای تصویر، سعی در تبدیل به webp نداریم — همان فایل را کپی می‌کنیم
        if (move_uploaded_file($tmp, $target)) {
            return 'private://tickets/' . $filename;
        }
        return '';
    }

    public function create()
    {
        $this->checkAuth();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/dashboard');
        }
        $tenant_id = Auth::tenantId();
        $subject = trim($_POST['subject'] ?? '');
        $category = trim($_POST['category'] ?? 'general');
        $message = trim($_POST['message'] ?? '');
        if (empty($subject) || empty($message)) {
            $this->setFlashMessage('عنوان تیکت و متن پیام الزامی هستند.');
            $this->redirect('/dashboard');
        }
        $attachment = $this->handleAttachment('attachment');
        if ($attachment) {
            $message .= "\n\n[پیوست: " . $attachment . "]";
        }
        $db = Bootstrap::getDB();
        $stmt = $db->prepare("INSERT INTO tickets (user_id, subject, category, message, status, attachment) VALUES (?, ?, ?, ?, 'open', ?)");
        $stmt->execute([$tenant_id, $subject, $category, $message, $attachment]);

        // ارسال نوتیفیکیشن پوش به ادمین‌ها
        try {
            \WHCM\Controllers\MainController::sendPushBroadcast('🎫 تیکت جدید', 'تیکت از «' . Auth::user()['name'] . '»: ' . mb_substr($subject, 0, 60), '/hnnh');
        } catch (\Throwable $e) {}

        $this->setFlashMessage('تیکت پشتیبانی شما با موفقیت ارسال شد و در صف پاسخگویی قرار گرفت. 🎫');
        $this->redirect('/dashboard');
    }

    public function userReply()
    {
        $this->checkAuth();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/dashboard');
        }
        $tenant_id = Auth::tenantId();
        $ticket_id = (int)($_POST['ticket_id'] ?? 0);
        $reply = trim($_POST['reply'] ?? '');
        $close = isset($_POST['close_after_reply']);
        if (empty($reply) || $ticket_id <= 0) {
            $this->setFlashMessage('متن پاسخ نمی‌تواند خالی باشد.');
            $this->redirect('/dashboard');
        }
        $db = Bootstrap::getDB();
        $stmt = $db->prepare("SELECT message, user_id FROM tickets WHERE id = ? LIMIT 1");
        $stmt->execute([$ticket_id]);
        $row = $stmt->fetch();
        if (!$row || (int)$row['user_id'] !== $tenant_id) {
            $this->setFlashMessage('تیکت یافت نشد یا دسترسی ندارید.');
            $this->redirect('/dashboard');
        }
        $attachment = $this->handleAttachment('attachment');
        if ($attachment) {
            $reply .= "\n\n[پیوست کاربر: " . $attachment . "]";
        }
        $new_msg = $row['message'] . "\n\n➖➖➖➖➖➖➖➖➖➖\n[پاسخ کاربر در تاریخ " . TextFormat::now_jalali() . "]:\n" . $reply;
        $status = $close ? 'closed' : 'open';
        $stmt = $db->prepare("UPDATE tickets SET message = ?, status = ?, attachment = COALESCE(?, attachment) WHERE id = ?");
        $stmt->execute([$new_msg, $status, $attachment ?: null, $ticket_id]);

        // ارسال نوتیفیکیشن پوش به ادمین‌ها (کاربر پاسخ داده)
        try {
            \WHCM\Controllers\MainController::sendPushBroadcast('👤 پاسخ کاربر به تیکت', mb_substr($reply, 0, 60), '/hnnh');
        } catch (\Throwable $e) {}

        $this->setFlashMessage($close ? 'پاسخ شما ثبت و تیکت بسته شد. ✔' : 'پاسخ شما با موفقیت ثبت شد. ✔');
        $this->redirect('/dashboard');
    }

    public function reply()
    {
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/hnnh');
        }
        $ticket_id = (int)($_POST['ticket_id'] ?? 0);
        $reply = trim($_POST['reply'] ?? '');
        $close = isset($_POST['close_after_reply']);
        $assigned_to = (int)($_POST['assigned_to'] ?? 0);
        if (empty($reply) || $ticket_id <= 0) {
            $this->setFlashMessage('متن پاسخ نمی‌تواند خالی باشد.');
            $this->redirect('/hnnh');
        }
        $db = Bootstrap::getDB();
        $stmt = $db->prepare("SELECT message FROM tickets WHERE id = ? LIMIT 1");
        $stmt->execute([$ticket_id]);
        $msg = $stmt->fetchColumn();
        if (!$msg) {
            $this->setFlashMessage('تیکت یافت نشد.');
            $this->redirect('/hnnh');
        }
        $attachment = $this->handleAttachment('attachment');
        if ($attachment) {
            $reply .= "\n\n[پیوست پشتیبان: " . $attachment . "]";
        }
        $new_msg = $msg . "\n\n➖➖➖➖➖➖➖➖➖➖\n[پاسخ پشتیبان در تاریخ " . TextFormat::now_jalali() . "]:\n" . $reply;
        $status = $close ? 'closed' : 'replied';
        $stmt = $db->prepare("UPDATE tickets SET message = ?, status = ?, assigned_to = ?, attachment = COALESCE(?, attachment) WHERE id = ?");
        $stmt->execute([$new_msg, $status, $assigned_to ?: null, $attachment ?: null, $ticket_id]);

        // ارسال نوتیفیکیشن پوش به کاربر صاحب تیکت
        try {
            $stmt_user = $db->prepare("SELECT user_id FROM tickets WHERE id = ? LIMIT 1");
            $stmt_user->execute([$ticket_id]);
            $ticket_user_id = (int)$stmt_user->fetchColumn();
            if ($ticket_user_id > 0) {
                \WHCM\Controllers\MainController::sendPushToUser($ticket_user_id, '📩 پاسخ جدید به تیکت شما', mb_substr($reply, 0, 80), '/dashboard');
            }
        } catch (\Throwable $e) {}

        $this->setFlashMessage($close ? 'پاسخ ثبت و تیکت بسته شد. ✔' : 'پاسخ شما به تیکت با موفقیت ثبت شد. ✔');
        $this->redirect('/hnnh');
    }

    public function closeAdmin()
    {
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/hnnh');
        }
        $ticket_id = (int)($_POST['ticket_id'] ?? 0);
        if ($ticket_id <= 0) {
            $this->setFlashMessage('شناسه تیکت نامعتبر است.');
            $this->redirect('/hnnh');
        }
        $db = Bootstrap::getDB();
        $stmt = $db->prepare("UPDATE tickets SET status = 'closed' WHERE id = ?");
        $stmt->execute([$ticket_id]);
        $this->setFlashMessage('تیکت با موفقیت مختومه و بسته شد. ✔');
        $this->redirect('/hnnh');
    }

    public function adminCreate()
    {
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/hnnh');
        }
        $target_user_id = (int)($_POST['target_user_id'] ?? 0);
        $subject = trim($_POST['subject'] ?? '');
        $category = trim($_POST['category'] ?? 'general');
        $message = trim($_POST['message'] ?? '');
        $priority = trim($_POST['priority'] ?? 'normal');

        if ($target_user_id <= 0) {
            $this->setFlashMessage('لطفاً کاربر مقصد را انتخاب کنید.');
            $this->redirect('/hnnh');
        }
        if (empty($subject) || empty($message)) {
            $this->setFlashMessage('عنوان و متن پیام الزامی هستند.');
            $this->redirect('/hnnh');
        }

        $db = Bootstrap::getDB();
        // بررسی وجود کاربر
        $stmt = $db->prepare("SELECT id, name FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$target_user_id]);
        $target_user = $stmt->fetch();
        if (!$target_user) {
            $this->setFlashMessage('کاربر مقصد یافت نشد.');
            $this->redirect('/hnnh');
        }

        $admin = Auth::user();
        $admin_name = $admin['name'] ?? 'مدیر سیستم';

        $attachment = $this->handleAttachment('attachment');
        if ($attachment) {
            $message .= "\n\n[پیوست: " . $attachment . "]";
        }

        // ذخیره اولین پیام ادمین در فیلد message
        $full_message = "[پیام مدیر سیستم ({$admin_name}) در تاریخ " . TextFormat::now_jalali() . "]:\n" . $message;

        $db = Bootstrap::getDB();
        try {
            $stmt = $db->prepare("INSERT INTO tickets (user_id, subject, category, message, status, priority, assigned_to, attachment, created_by_admin) VALUES (?, ?, ?, ?, 'replied', ?, ?, ?, 1)");
            $stmt->execute([$target_user_id, $subject, $category, $full_message, $priority, Auth::tenantId(), $attachment]);
        } catch (\PDOException $e) {
            // اگر ستون‌های جدید هنوز اضافه نشده‌اند، بدون آن‌ها ذخیره کنیم
            $stmt = $db->prepare("INSERT INTO tickets (user_id, subject, category, message, status, assigned_to, attachment) VALUES (?, ?, ?, ?, 'replied', ?, ?)");
            $stmt->execute([$target_user_id, $subject, $category, $full_message, Auth::tenantId(), $attachment]);
        }

        $this->setFlashMessage('پیام شما با موفقیت به کاربر «' . htmlspecialchars($target_user['name']) . '» ارسال شد. ✔');
        $this->redirect('/hnnh');
    }

    /**
     * باز کردن مجدد تیکت بسته‌شده توسط ادمین
     */
    public function reopenAdmin()
    {
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/hnnh');
        }
        $ticket_id = (int)($_POST['ticket_id'] ?? 0);
        if ($ticket_id <= 0) {
            $this->setFlashMessage('شناسه تیکت نامعتبر است.');
            $this->redirect('/hnnh');
        }
        $db = Bootstrap::getDB();
        $stmt = $db->prepare("UPDATE tickets SET status = 'open' WHERE id = ?");
        $stmt->execute([$ticket_id]);
        $this->setFlashMessage('تیکت مجدداً باز شد. 🎫');
        $this->redirect('/hnnh');
    }

    /**
     * حذف تیکت توسط ادمین
     */
    public function deleteAdmin()
    {
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/hnnh');
        }
        $ticket_id = (int)($_POST['ticket_id'] ?? 0);
        if ($ticket_id <= 0) {
            $this->setFlashMessage('شناسه تیکت نامعتبر است.');
            $this->redirect('/hnnh');
        }
        $db = Bootstrap::getDB();
        $stmt = $db->prepare("DELETE FROM tickets WHERE id = ?");
        $stmt->execute([$ticket_id]);
        $this->setFlashMessage('تیکت با موفقیت حذف شد. 🗑');
        $this->redirect('/hnnh');
    }

    public function closeUser()
    {
        $this->checkAuth();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/dashboard');
        }
        $ticket_id = (int)($_POST['ticket_id'] ?? 0);
        $tenant_id = Auth::tenantId();
        if ($ticket_id <= 0) {
            $this->setFlashMessage('شناسه تیکت نامعتبر است.');
            $this->redirect('/dashboard');
        }
        $db = Bootstrap::getDB();
        $stmt = $db->prepare("SELECT id FROM tickets WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$ticket_id, $tenant_id]);
        if (!$stmt->fetch()) {
            $this->setFlashMessage('تیکت یافت نشد یا دسترسی ندارید.');
            $this->redirect('/dashboard');
        }
        $stmt = $db->prepare("UPDATE tickets SET status = 'closed' WHERE id = ?");
        $stmt->execute([$ticket_id]);
        $this->setFlashMessage('تیکت با موفقیت بسته شد. ✔');
        $this->redirect('/dashboard');
    }
}
