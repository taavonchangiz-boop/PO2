<?php
namespace WHCM\Api\Controllers;

use WHCM\Api\MobileApiResponse;
use WHCM\Core\Bootstrap;
use WHCM\Domain\PaymentSettlement;
use WHCM\Domain\Notification;

/**
 * کنترلر API پنل مدیریت (سوپر ادمین)
 *
 * شامل: داشبورد، مدیریت کاربران، پرداخت‌ها، تیکت‌ها، پلن‌ها،
 * اعلان سراسری، کدهای تخفیف
 *
 * تمام متدها نیازمند دسترسی superadmin هستند.
 *
 * @package WHCM\Api\Controllers
 */
class AdminApiController extends \WHCM\Api\MobileApiController {

    /**
     * دریافت آمار داشبورد مدیریت
     * GET /api/v1/admin/dashboard (superadmin)
     */
    public function dashboard(): void {
        $this->requireSuperAdmin();
        $db = $this->db();

        // آمار کاربران
        $stmt = $db->query("SELECT COUNT(*) as total FROM users WHERE role != 'superadmin'");
        $totalUsers = (int)$stmt->fetch()['total'];

        $stmt = $db->query("SELECT COUNT(*) as total FROM users WHERE role != 'superadmin' AND status = 'active'");
        $activeUsers = (int)$stmt->fetch()['total'];

        $stmt = $db->query("SELECT COUNT(*) as total FROM users WHERE role != 'superadmin' AND status = 'suspended'");
        $suspendedUsers = (int)$stmt->fetch()['total'];

        // آمار پرداخت‌ها
        $stmt = $db->query("SELECT COUNT(*) as total, COALESCE(SUM(amount), 0) as total_amount FROM payments");
        $paymentRow = $stmt->fetch();
        $totalPayments = (int)$paymentRow['total'];
        $totalAmount   = (float)$paymentRow['total_amount'];

        $stmt = $db->query("SELECT COUNT(*) as total FROM payments WHERE status = 'pending'");
        $pendingPayments = (int)$stmt->fetch()['total'];

        $stmt = $db->query("SELECT COUNT(*) as total FROM payments WHERE status = 'approved'");
        $approvedPayments = (int)$stmt->fetch()['total'];

        // آمار تیکت‌ها
        $stmt = $db->query("SELECT COUNT(*) as total FROM tickets");
        $totalTickets = (int)$stmt->fetch()['total'];

        $stmt = $db->query("SELECT COUNT(*) as total FROM tickets WHERE status = 'open'");
        $openTickets = (int)$stmt->fetch()['total'];

        // آخرین ثبت‌نام‌ها
        $stmt = $db->query("SELECT id, name, email, status, role, created_at FROM users WHERE role != 'superadmin' ORDER BY id DESC LIMIT 10");
        $recentUsers = $stmt->fetchAll();

        MobileApiResponse::success([
            'users'    => [
                'total'     => $totalUsers,
                'active'    => $activeUsers,
                'suspended' => $suspendedUsers,
            ],
            'payments' => [
                'total'    => $totalPayments,
                'amount'   => $totalAmount,
                'pending'  => $pendingPayments,
                'approved' => $approvedPayments,
            ],
            'tickets'  => [
                'total' => $totalTickets,
                'open'  => $openTickets,
            ],
            'recent_users' => $recentUsers,
        ]);
    }

    /**
     * دریافت لیست کاربران
     * GET /api/v1/admin/users (superadmin)
     *
     * Query params: status (optional), search (optional), limit (default 50), offset (default 0)
     */
    public function users(): void {
        $this->requireSuperAdmin();
        $db = $this->db();

        $status = $this->get('status');
        $search = $this->get('search');
        $limit  = (int)($this->get('limit') ?? 50);
        $offset = (int)($this->get('offset') ?? 0);

        if ($limit < 1) $limit = 50;
        if ($limit > 200) $limit = 200;
        if ($offset < 0) $offset = 0;

        $params = [];
        $sql = "SELECT * FROM users WHERE role != 'superadmin'";

        if ($status !== null && $status !== '') {
            $sql .= " AND status = ?";
            $params[] = $status;
        }

        if ($search !== null && $search !== '') {
            $sql .= " AND (name LIKE ? OR email LIKE ?)";
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }

        $sql .= " ORDER BY id DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll();

        // اطلاعات تکمیلی هر کاربر
        foreach ($users as &$user) {
            $uid = (int)$user['id'];

            // تعداد کانال‌ها
            $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM channels WHERE tenant_id = ?");
            $stmt->execute([$uid]);
            $user['channels_count'] = (int)$stmt->fetch()['cnt'];

            // تعداد پست‌ها
            $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM posts WHERE tenant_id = ?");
            $stmt->execute([$uid]);
            $user['posts_count'] = (int)$stmt->fetch()['cnt'];

            // اشتراک فعال
            $stmt = $db->prepare("SELECT s.id, s.end_date, p.title as plan_title FROM subscriptions s JOIN plans p ON s.plan_id = p.id WHERE s.user_id = ? AND s.status = 'active' ORDER BY s.id DESC LIMIT 1");
            $stmt->execute([$uid]);
            $sub = $stmt->fetch();
            $user['active_subscription'] = $sub ?: null;
        }
        unset($user);

        MobileApiResponse::success($users);
    }

    /**
     * معلق کردن کاربر
     * POST /api/v1/admin/users/{id}/suspend (superadmin)
     *
     * @param string $id شناسه کاربر از مسیر
     */
    public function suspendUser(string $id): void {
        $this->requireSuperAdmin();
        $db    = $this->db();
        $uid   = (int)$id;

        $stmt = $db->prepare("UPDATE users SET status = 'suspended' WHERE id = ? AND role != 'superadmin'");
        $stmt->execute([$uid]);

        if ($stmt->rowCount() === 0) {
            MobileApiResponse::error('کاربر یافت نشد یا قادر به تغییر وضعیت ادمین نیستید.', 404);
        }

        MobileApiResponse::success(null, 'کاربر با موفقیت معلق شد.');
    }

    /**
     * فعال کردن کاربر
     * POST /api/v1/admin/users/{id}/activate (superadmin)
     *
     * @param string $id شناسه کاربر از مسیر
     */
    public function activateUser(string $id): void {
        $this->requireSuperAdmin();
        $db  = $this->db();
        $uid = (int)$id;

        $stmt = $db->prepare("UPDATE users SET status = 'active' WHERE id = ?");
        $stmt->execute([$uid]);

        if ($stmt->rowCount() === 0) {
            MobileApiResponse::error('کاربر یافت نشد.', 404);
        }

        MobileApiResponse::success(null, 'کاربر با موفقیت فعال شد.');
    }

    /**
     * دریافت لیست پرداخت‌ها (مدیریت)
     * GET /api/v1/admin/payments (superadmin)
     *
     * Query params: status (optional), limit (default 50), offset (default 0)
     */
    public function payments(): void {
        $this->requireSuperAdmin();
        $db = $this->db();

        $status = $this->get('status');
        $limit  = (int)($this->get('limit') ?? 50);
        $offset = (int)($this->get('offset') ?? 0);

        if ($limit < 1) $limit = 50;
        if ($limit > 200) $limit = 200;
        if ($offset < 0) $offset = 0;

        $params = [];
        $sql = "
            SELECT pay.*, u.name as user_name, p.title as plan_title
            FROM payments pay
            JOIN users u ON pay.user_id = u.id
            LEFT JOIN plans p ON pay.plan_id = p.id
            WHERE 1=1
        ";

        if ($status !== null && $status !== '') {
            $sql .= " AND pay.status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY pay.id DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        MobileApiResponse::success($stmt->fetchAll());
    }

    /**
     * تایید پرداخت
     * POST /api/v1/admin/payments/{id}/approve (superadmin)
     *
     * @param string $id شناسه پرداخت از مسیر
     */
    public function approvePayment(string $id): void {
        $this->requireSuperAdmin();
        $paymentId = (int)$id;
        if ($paymentId <= 0) { MobileApiResponse::error('شناسه پرداخت نامعتبر است.', 400); }
        try {
            $result = PaymentSettlement::approve($paymentId);
            $userId = (int)$result['user_id'];
            $planTitle = (string)$result['plan_title'];
            try {
                \WHCM\Controllers\MainController::sendPushToUser($userId, '✅ اشتراک شما فعال شد!', 'پلن «' . $planTitle . '» با موفقیت فعال گردید. ✔', '/dashboard');
            } catch (\Throwable $e) {}
            try {
                Notification::create($userId, '✅ اشتراک شما فعال شد', 'پلن «' . $planTitle . '» با موفقیت فعال گردید و از همین لحظه قابل استفاده است.', 'subscription', 'upgrade');
            } catch (\Throwable $e) {}
            MobileApiResponse::success(null, 'پرداخت با موفقیت تایید و اشتراک کاربر فعال شد.');
        } catch (\Throwable $e) {
            MobileApiResponse::error('خطا در پردازش تایید تراکنش: ' . $e->getMessage(), 409);
        }
    }

    /**
     * دریافت لیست تیکت‌ها (مدیریت)
     * GET /api/v1/admin/tickets (superadmin)
     */
    public function tickets(): void {
        $this->requireSuperAdmin();
        $db = $this->db();

        $stmt = $db->prepare("
            SELECT t.*, u.name as user_name
            FROM tickets t
            JOIN users u ON t.user_id = u.id
            ORDER BY t.id DESC
        ");
        $stmt->execute();

        $tickets = $stmt->fetchAll();

        // تعداد پاسخ‌ها برای هر تیکت
        foreach ($tickets as &$ticket) {
            $ticketId = (int)$ticket['id'];
            $stmt = $db->prepare("SELECT COUNT(*) as reply_count FROM ticket_replies WHERE ticket_id = ?");
            $stmt->execute([$ticketId]);
            $ticket['replies_count'] = (int)$stmt->fetch()['reply_count'];
        }
        unset($ticket);

        MobileApiResponse::success($tickets);
    }

    /**
     * پاسخ ادمین به تیکت
     * POST /api/v1/admin/tickets/{id}/reply (superadmin)
     *
     * Input: message (required), close_after_reply (optional)
     *
     * @param string $id شناسه تیکت از مسیر
     */
    public function replyTicket(string $id): void {
        $this->requireSuperAdmin();
        $db       = $this->db();
        $ticketId = (int)$id;
        $input    = $this->input();
        $admin    = $this->user();

        $errors = $this->validate([
            'message' => 'required',
        ], $input);

        if (!empty($errors)) {
            MobileApiResponse::validationError($errors);
        }

        $message         = trim($input['message']);
        $closeAfterReply = !empty($input['close_after_reply']);

        // دریافت تیکت
        $stmt = $db->prepare("SELECT * FROM tickets WHERE id = ? LIMIT 1");
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch();

        if (!$ticket) {
            MobileApiResponse::notFound('تیکت مورد نظر یافت نشد.');
        }

        // ذخیره پاسخ
        $stmt = $db->prepare("
            INSERT INTO ticket_replies (ticket_id, user_id, message)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$ticketId, $admin['id'], $message]);

        // بروزرسانی وضعیت تیکت
        $newStatus = $closeAfterReply ? 'closed' : 'replied';
        $stmt = $db->prepare("UPDATE tickets SET status = ?, assigned_to = ? WHERE id = ?");
        $stmt->execute([$newStatus, $admin['id'], $ticketId]);

        // ارسال نوتیفیکیشن پوش به کاربر صاحب تیکت
        try {
            $ticketUserId = (int)$ticket['user_id'];
            \WHCM\Controllers\MainController::sendPushToUser(
                $ticketUserId,
                '📩 پاسخ جدید به تیکت شما',
                mb_substr($message, 0, 80),
                '/dashboard'
            );
        } catch (\Throwable $e) {}

        MobileApiResponse::success([
            'ticket_id' => $ticketId,
            'status'    => $newStatus,
        ], $closeAfterReply ? 'پاسخ ثبت و تیکت بسته شد.' : 'پاسخ با موفقیت ثبت شد.');
    }

    /**
     * دریافت لیست پلن‌ها (مدیریت)
     * GET /api/v1/admin/plans (superadmin)
     */
    public function plans(): void {
        $this->requireSuperAdmin();
        $db = $this->db();

        $stmt = $db->query("SELECT * FROM plans ORDER BY price ASC");
        $plans = $stmt->fetchAll();

        foreach ($plans as &$plan) {
            $plan['features'] = json_decode($plan['features'] ?? '[]', true) ?: [];
        }
        unset($plan);

        MobileApiResponse::success($plans);
    }

    /**
     * ایجاد پلن جدید
     * POST /api/v1/admin/plans (superadmin)
     *
     * Input: title, price, duration_days, max_channels, max_posts, features, description, is_featured
     */
    public function createPlan(): void {
        $this->requireSuperAdmin();
        $db    = $this->db();
        $input = $this->input();

        $errors = $this->validate([
            'title' => 'required',
        ], $input);

        if (!empty($errors)) {
            MobileApiResponse::validationError($errors);
        }

        $title        = trim($input['title'] ?? '');
        $price        = (float)($input['price'] ?? 0);
        $durationDays = (int)($input['duration_days'] ?? 30);
        $maxChannels  = (int)($input['max_channels'] ?? 1);
        $maxPosts     = (int)($input['max_posts'] ?? 10);
        $features     = $input['features'] ?? [];
        $description  = trim($input['description'] ?? '');
        $isFeatured   = (int)($input['is_featured'] ?? 0);

        // تبدیل features به JSON
        if (is_array($features)) {
            $features = json_encode($features, JSON_UNESCAPED_UNICODE);
        }

        $stmt = $db->prepare("
            INSERT INTO plans (title, price, duration_days, max_channels, max_posts, features, description, is_featured)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$title, $price, $durationDays, $maxChannels, $maxPosts, $features, $description, $isFeatured]);

        $planId = (int)$db->lastInsertId();

        $stmt = $db->prepare("SELECT * FROM plans WHERE id = ?");
        $stmt->execute([$planId]);
        $plan = $stmt->fetch();
        $plan['features'] = json_decode($plan['features'] ?? '[]', true) ?: [];

        MobileApiResponse::success($plan, 'پلن با موفقیت ایجاد شد.');
    }

    /**
     * بروزرسانی پلن
     * PUT /api/v1/admin/plans/{id} (superadmin)
     *
     * @param string $id شناسه پلن از مسیر
     */
    public function updatePlan(string $id): void {
        $this->requireSuperAdmin();
        $db     = $this->db();
        $planId = (int)$id;
        $input  = $this->input();

        // بررسی وجود پلن
        $stmt = $db->prepare("SELECT id FROM plans WHERE id = ? LIMIT 1");
        $stmt->execute([$planId]);
        if (!$stmt->fetch()) {
            MobileApiResponse::notFound('پلن مورد نظر یافت نشد.');
        }

        $title        = trim($input['title'] ?? '');
        $price        = (float)($input['price'] ?? 0);
        $durationDays = (int)($input['duration_days'] ?? 30);
        $maxChannels  = (int)($input['max_channels'] ?? 1);
        $maxPosts     = (int)($input['max_posts'] ?? 10);
        $features     = $input['features'] ?? [];
        $description  = trim($input['description'] ?? '');
        $isFeatured   = (int)($input['is_featured'] ?? 0);

        if (is_array($features)) {
            $features = json_encode($features, JSON_UNESCAPED_UNICODE);
        }

        $stmt = $db->prepare("
            UPDATE plans
            SET title = ?, price = ?, duration_days = ?, max_channels = ?, max_posts = ?,
                features = ?, description = ?, is_featured = ?
            WHERE id = ?
        ");
        $stmt->execute([$title, $price, $durationDays, $maxChannels, $maxPosts, $features, $description, $isFeatured, $planId]);

        $stmt = $db->prepare("SELECT * FROM plans WHERE id = ?");
        $stmt->execute([$planId]);
        $plan = $stmt->fetch();
        $plan['features'] = json_decode($plan['features'] ?? '[]', true) ?: [];

        MobileApiResponse::success($plan, 'پلن با موفقیت بروزرسانی شد.');
    }

    /**
     * حذف پلن
     * DELETE /api/v1/admin/plans/{id} (superadmin)
     *
     * @param string $id شناسه پلن از مسیر
     */
    public function deletePlan(string $id): void {
        $this->requireSuperAdmin();
        $db     = $this->db();
        $planId = (int)$id;

        $stmt = $db->prepare("DELETE FROM plans WHERE id = ?");
        $stmt->execute([$planId]);

        if ($stmt->rowCount() === 0) {
            MobileApiResponse::notFound('پلن مورد نظر یافت نشد.');
        }

        MobileApiResponse::success(null, 'پلن با موفقیت حذف شد.');
    }

    /**
     * ارسال اعلان سراسری
     * POST /api/v1/admin/broadcast (superadmin)
     *
     * Input: title (required), message (required)
     */
    public function broadcast(): void {
        $this->requireSuperAdmin();
        $db    = $this->db();
        $input = $this->input();

        $errors = $this->validate([
            'title'   => 'required',
            'message' => 'required',
        ], $input);

        if (!empty($errors)) {
            MobileApiResponse::validationError($errors);
        }

        $title   = trim($input['title']);
        $message = trim($input['message']);

        // ذخیره در تنظیمات (global_announcement)
        $announcementData = json_encode([
            'id'         => time(),
            'title'      => $title,
            'message'    => $message,
            'created_at' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE);

        $stmt = $db->prepare("SELECT id FROM settings WHERE tenant_id = 0 AND key_name = 'global_announcement' LIMIT 1");
        $stmt->execute();

        if ($stmt->fetch()) {
            $stmt = $db->prepare("UPDATE settings SET key_value = ? WHERE tenant_id = 0 AND key_name = 'global_announcement'");
        } else {
            $stmt = $db->prepare("INSERT INTO settings (tenant_id, key_name, key_value) VALUES (0, 'global_announcement', ?)");
        }
        $stmt->execute([$announcementData]);

        // ارسال اعلان به تمام کاربران
        $count = Notification::broadcast($title, $message, 'announcement');

        MobileApiResponse::success([
            'notified_count' => $count,
        ], 'اعلان سراسری با موفقیت ارسال شد.');
    }

    /**
     * ایجاد کد تخفیف
     * POST /api/v1/admin/discounts (superadmin)
     *
     * Input: code (required), percentage (required), max_uses, expires_at
     */
    public function addDiscount(): void {
        $this->requireSuperAdmin();
        $db    = $this->db();
        $input = $this->input();

        $errors = $this->validate([
            'code'       => 'required',
            'percentage' => 'required',
        ], $input);

        if (!empty($errors)) {
            MobileApiResponse::validationError($errors);
        }

        $code       = trim($input['code']);
        $percentage = (float)$input['percentage'];
        $maxUses    = (int)($input['max_uses'] ?? 0);
        $expiresAt  = trim($input['expires_at'] ?? '');

        if (empty($expiresAt)) {
            $expiresAt = null;
        }

        $stmt = $db->prepare("
            INSERT INTO discount_codes (code, type, amount, max_uses, expires_at, active)
            VALUES (?, 'percent', ?, ?, ?, 1)
        ");
        $stmt->execute([$code, $percentage, $maxUses, $expiresAt]);

        $discountId = (int)$db->lastInsertId();

        $stmt = $db->prepare("SELECT * FROM discount_codes WHERE id = ?");
        $stmt->execute([$discountId]);

        MobileApiResponse::success($stmt->fetch(), 'کد تخفیف با موفقیت ایجاد شد.');
    }

    /**
     * حذف کد تخفیف
     * DELETE /api/v1/admin/discounts/{id} (superadmin)
     *
     * @param string $id شناسه کد تخفیف از مسیر
     */
    public function deleteDiscount(string $id): void {
        $this->requireSuperAdmin();
        $db           = $this->db();
        $discountId   = (int)$id;

        $stmt = $db->prepare("DELETE FROM discount_codes WHERE id = ?");
        $stmt->execute([$discountId]);

        if ($stmt->rowCount() === 0) {
            MobileApiResponse::notFound('کد تخفیف مورد نظر یافت نشد.');
        }

        MobileApiResponse::success(null, 'کد تخفیف با موفقیت حذف شد.');
    }
}
