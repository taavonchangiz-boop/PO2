<?php
namespace WHCM\Modules\Users\Controllers;

use WHCM\Core\Bootstrap;
use WHCM\Core\Auth;
use WHCM\Core\Csrf;
use WHCM\Domain\Notification;
use WHCM\Controllers\BaseController;

/**
 * کنترلر ماژول Users — مدیریت کاربران
 * قدم ۲-الف
 */
class UserController extends BaseController
{
    public function addManual()
    {
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/hnnh');
        }
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $phone = trim($_POST['phone'] ?? '');
        $business_name = trim($_POST['business_name'] ?? '');
        $business_type = trim($_POST['business_type'] ?? '');
        $role = trim($_POST['role'] ?? 'user');
        
        // فقط نقش‌های مجاز
        $allowed_roles = ['user', 'support_agent'];
        if (!in_array($role, $allowed_roles, true)) {
            $role = 'user';
        }
        
        if (empty($name) || empty($email) || empty($password) || empty($phone)) {
            $this->setFlashMessage('پر کردن فیلدهای نام، ایمیل و کلمه عبور الزامی است.');
            $this->redirect('/hnnh');
        }
        $res = Auth::register($name, $email, $password, $business_name, $business_type, $phone);
        if ($res['success']) {
            // تغییر نقش اگر پشتیبان باشد
            if ($role === 'support_agent' && !empty($res['user_id'])) {
                $db = Bootstrap::getDB();
                $db->prepare("UPDATE users SET role = 'support_agent' WHERE id = ?")->execute([$res['user_id']]);
                $this->setFlashMessage('کاربر پشتیبان با موفقیت ایجاد شد! ✔ (دسترسی محدود به تیکت‌ها)');
            } else {
                $this->setFlashMessage('کاربر جدید با موفقیت به صورت دستی ثبت و ایجاد شد! ✔');
            }
        } else {
            $this->setFlashMessage($res['message']);
        }
        $this->redirect('/hnnh');
    }

    public function grantSubscription()
    {
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/hnnh');
        }
        $user_id = (int)($_POST['user_id'] ?? 0);
        $plan_id = (int)($_POST['plan_id'] ?? 0);
        if ($user_id <= 0 || $plan_id <= 0) {
            $this->setFlashMessage('لطفاً کاربر و پلن اشتراک مورد نظر را انتخاب کنید.');
            $this->redirect('/hnnh');
        }
        $db = Bootstrap::getDB();
        $stmt = $db->prepare("SELECT duration_days FROM plans WHERE id = ? LIMIT 1");
        $stmt->execute([$plan_id]);
        $plan = $stmt->fetch();
        if (!$plan) {
            $this->setFlashMessage('پلن انتخابی نامعتبر است.');
            $this->redirect('/hnnh');
        }
        $db->beginTransaction();
        try {
            $lockSql = Bootstrap::getConfig('database.driver', 'sqlite') === 'mysql'
                ? "SELECT id FROM users WHERE id = ? LIMIT 1 FOR UPDATE"
                : "SELECT id FROM users WHERE id = ? LIMIT 1";
            $lockStmt = $db->prepare($lockSql);
            $lockStmt->execute([$user_id]);
            if (!$lockStmt->fetch()) throw new \RuntimeException('کاربر اشتراک یافت نشد.');

            $stmt = $db->prepare("UPDATE subscriptions SET status = 'expired' WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $now = date('Y-m-d H:i:s');
            $duration = (int)$plan['duration_days'];
            $end_date = $duration > 0 ? date('Y-m-d H:i:s', strtotime("+{$duration} days")) : '2099-12-30 00:00:00';
            $stmt = $db->prepare("INSERT INTO subscriptions (user_id, plan_id, start_date, end_date, status) VALUES (?, ?, ?, ?, 'active')");
            $stmt->execute([$user_id, $plan_id, $now, $end_date]);
            $db->commit();

            // ثبت اعلان درون‌برنامه‌ای برای کاربر
            try {
                $stmt_p = $db->prepare("SELECT title FROM plans WHERE id = ? LIMIT 1");
                $stmt_p->execute([$plan_id]);
                $plan_title = $stmt_p->fetchColumn() ?: 'پلن اشتراکی';
                Notification::create($user_id, '💎 اشتراک جدید اعطا شد', 'اشتراک «' . $plan_title . '» به صورت دستی توسط مدیریت برای شما فعال گردید.', 'subscription', 'upgrade');
            } catch (\Throwable $e) {}

            $this->setFlashMessage('اشتراک انتخابی با موفقیت به صورت دستی به کاربر اعطا و فعال گردید! ✔💎');
        } catch (\Exception $e) {
            $db->rollBack();
            $this->setFlashMessage('خطا در اعطای اشتراک: ' . $e->getMessage());
        }
        $this->redirect('/hnnh');
    }

    public function suspend()
    {
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/hnnh');
        }
        $id = (int)($_POST['user_id'] ?? 0);
        $db = Bootstrap::getDB();
        $stmt = $db->prepare("UPDATE users SET status = 'suspended' WHERE id = ? AND role != 'superadmin'");
        $stmt->execute([$id]);
        $this->setFlashMessage('حساب کاربری مستأجر با موفقیت معلق و مسدود گردید. 🚫');
        $this->redirect('/hnnh');
    }

    public function activate()
    {
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/hnnh');
        }
        $id = (int)($_POST['user_id'] ?? 0);
        $db = Bootstrap::getDB();
        $stmt = $db->prepare("UPDATE users SET status = 'active' WHERE id = ?");
        $stmt->execute([$id]);
        $this->setFlashMessage('حساب کاربری مستأجر با موفقیت مجدداً فعال شد. ✔');
        $this->redirect('/hnnh');
    }

    public function delete()
    {
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/hnnh');
        }
        $id = (int)($_POST['user_id'] ?? 0);
        $db = Bootstrap::getDB();
        $stmt = $db->prepare("DELETE FROM users WHERE id = ? AND role != 'superadmin'");
        $stmt->execute([$id]);
        $this->setFlashMessage('حساب کاربری مستأجر با موفقیت به طور کامل حذف گردید.');
        $this->redirect('/hnnh');
    }

    public function wipeTestData()
    {
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/hnnh');
        }
        $db = Bootstrap::getDB();
        $db->exec("DELETE FROM users WHERE email = 'stranger@belitia.ir' OR email = 'hooman@belitia.ir' OR name = 'هومن راد'");
        $this->setFlashMessage('تمامی اطلاعات تستی و فرضی قبلی با موفقیت ۱۰۰٪ از دیتابیس پاکسازی شدند! ✔');
        $this->redirect('/hnnh');
    }
}
