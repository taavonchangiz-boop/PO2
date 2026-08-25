<?php
namespace WHCM\Api\Controllers;

use WHCM\Api\MobileApiResponse;
use WHCM\Api\MobileApiAuth;
use WHCM\Core\Bootstrap;
use WHCM\Domain\Quota;
use WHCM\Domain\Sender;
use WHCM\Domain\ScheduledPost;
use WHCM\Domain\TextFormat;
use WHCM\Domain\LinkTracker;

/**
 * کنترلر API مدیریت پست‌ها
 *
 * عملیات ایجاد، مشاهده، لغو و تلاش مجدد پست‌ها برای اپلیکیشن موبایل
 *
 * @package WHCM\Api\Controllers
 */
class PostApiController extends \WHCM\Api\MobileApiController {

    /**
     * دریافت لیست پست‌های کاربر
     * GET /api/v1/posts (auth)
     *
     * Query params: status (optional filter), limit (default 50), offset (default 0)
     */
    public function index(): void {
        $tenant_id = $this->userId();
        $db = $this->db();

        $status = $this->get('status');
        $limit  = (int)($this->get('limit') ?? 50);
        $offset = (int)($this->get('offset') ?? 0);
        $beforeId = (int)($this->get('before_id') ?? 0);

        // محدود کردن مقادیر
        if ($limit < 1) $limit = 50;
        if ($limit > 200) $limit = 200;
        if ($offset < 0) $offset = 0;
        // Deep OFFSET pagination becomes progressively expensive. Preserve the
        // legacy parameter for compatibility, but force clients toward cursor paging.
        if ($offset > 5000) $offset = 5000;

        if ($status !== null) {
            $status = trim($status);
        }

        // Cursor pagination (before_id) is O(log n) with the tenant/status/id index.
        // Offset remains supported for old clients.
        $where = ' WHERE tenant_id = ?';
        $params = [$tenant_id];
        if ($status !== null && $status !== '') { $where .= ' AND status = ?'; $params[] = $status; }
        if ($beforeId > 0) {
            $where .= ' AND id < ?';
            $params[] = $beforeId;
            $sql = "SELECT * FROM posts{$where} ORDER BY id DESC LIMIT ?";
            $params[] = $limit;
        } else {
            $sql = "SELECT * FROM posts{$where} ORDER BY id DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $posts = $stmt->fetchAll();

        // دریافت تعداد کلیک برای هر پست از clicks_log
        $postIds = array_column($posts, 'id');
        $clickCounts = [];
        if (!empty($postIds)) {
            $placeholders = implode(',', array_fill(0, count($postIds), '?'));
            $stmt = $db->prepare(
                "SELECT post_id, COUNT(*) as click_count FROM clicks_log WHERE post_id IN ({$placeholders}) GROUP BY post_id"
            );
            $stmt->execute($postIds);
            $clickRows = $stmt->fetchAll();
            foreach ($clickRows as $row) {
                $clickCounts[(int)$row['post_id']] = (int)$row['click_count'];
            }
        }

        // اضافه کردن تعداد کلیک به هر پست
        foreach ($posts as &$post) {
            $post['click_count'] = $clickCounts[(int)$post['id']] ?? 0;
        }
        unset($post);

        // Preserve the existing response shape for mobile clients. Clients can
        // use the last returned id as before_id on the next request.
        MobileApiResponse::success($posts);
    }

    /**
     * ایجاد پست جدید
     * POST /api/v1/posts (auth)
     *
     * Input: title (required), content (required), send_type (required: 'instant' or 'scheduled'),
     *        sched_date (optional), sched_hour (optional), sched_minute (optional),
     *        post_channels (required array of channel IDs), caption_format (optional)
     * Optional file upload: media_file (image)
     */
    public function store(): void {
        $tenant_id = $this->userId();
        $db = $this->db();
        $input = $this->input();

        // اعتبارسنجی فیلدهای الزامی
        $errors = $this->validate([
            'title'        => 'required',
            'content'      => 'required',
            'send_type'    => 'required',
            'post_channels' => 'required',
        ], $input);

        if (!empty($errors)) {
            MobileApiResponse::validationError($errors);
        }

        $title   = trim($input['title']);
        $content = trim($input['content']);
        $send_type = trim($input['send_type']);

        // بررسی اعتبار send_type
        if (!in_array($send_type, ['instant', 'scheduled'])) {
            MobileApiResponse::validationError([
                'send_type' => 'مقدار send_type باید instant یا scheduled باشد.'
            ]);
        }

        // بررسی post_channels غیرخالی
        $post_channels = $input['post_channels'];
        if (!is_array($post_channels) || empty($post_channels)) {
            MobileApiResponse::validationError([
                'post_channels' => 'حداقل یک کانال باید انتخاب شود.'
            ]);
        }

        // تبدیل به آرایه‌ای از اعداد صحیح
        $channelIds = array_map('intval', $post_channels);
        $channelIds = array_filter($channelIds, fn($id) => $id > 0);
        $channelIds = array_values($channelIds);

        if (empty($channelIds)) {
            MobileApiResponse::validationError([
                'post_channels' => 'شناسه‌های کانال نامعتبر هستند.'
            ]);
        }

        // بررسی سهمیه
        $quota = Quota::getTenantQuota($tenant_id);
        if (!$quota['can_send_post']) {
            MobileApiResponse::error('سهمیه ارسال پست شما به پایان رسیده است. لطفاً پلن خود را ارتقا دهید.', 403);
        }

        // آپلود رسانه در صورت وجود
        $mediaUrl = $this->uploadImage('media_file', 'posts');

        // تعیین وضعیت و زمان‌بندی
        $status = 'queued';
        $scheduled_at = null;

        if ($send_type === 'scheduled') {
            $sched_date   = trim($input['sched_date'] ?? '');
            $sched_hour   = trim($input['sched_hour'] ?? '0');
            $sched_minute = trim($input['sched_minute'] ?? '0');

            if (empty($sched_date)) {
                MobileApiResponse::validationError([
                    'sched_date' => 'برای پست زمان‌بندی‌شده، تاریخ الزامی است.'
                ]);
            }

            // تبدیل ارقام فارسی/عربی به لاتین
            $sched_date   = TextFormat::en_num($sched_date);
            $sched_hour   = TextFormat::en_num($sched_hour);
            $sched_minute = TextFormat::en_num($sched_minute);

            // تجزیه تاریخ جلالی (فرمت: YYYY/MM/DD)
            $dateParts = explode('/', $sched_date);
            if (count($dateParts) !== 3) {
                MobileApiResponse::validationError([
                    'sched_date' => 'فرمت تاریخ نامعتبر است. از فرمت YYYY/MM/DD استفاده کنید.'
                ]);
            }

            $jy = (int)$dateParts[0];
            $jm = (int)$dateParts[1];
            $jd = (int)$dateParts[2];
            $hour = (int)$sched_hour;
            $minute = (int)$sched_minute;

            // اعتبارسنجی بازه‌ها
            if ($jy < 1300 || $jy > 1500 || $jm < 1 || $jm > 12 || $jd < 1 || $jd > 31) {
                MobileApiResponse::validationError([
                    'sched_date' => 'مقادیر تاریخ شمسی نامعتبر هستند.'
                ]);
            }
            if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
                MobileApiResponse::validationError([
                    'sched_hour' => 'مقادیر ساعت نامعتبر هستند.'
                ]);
            }

            // تبدیل جلالی به میلادی
            $gregorian = $this->jalaliToGregorian($jy, $jm, $jd);
            $gy = $gregorian[0];
            $gm = $gregorian[1];
            $gd = $gregorian[2];

            $scheduled_at = sprintf('%04d-%02d-%02d %02d:%02d:00', $gy, $gm, $gd, $hour, $minute);
            $status = 'scheduled';
        }

        // اعمال قالب کپشن در صورت ارائه
        $caption_format = trim($input['caption_format'] ?? '');
        if (!empty($caption_format)) {
            $content = str_replace('{title}', $title, $caption_format) . "\n" . $content;
        }

        // ذخیره پست در دیتابیس
        $stmt = $db->prepare("
            INSERT INTO posts (tenant_id, title, content, media_url, status, scheduled_at, target_channels)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $tenant_id,
            $title,
            $content,
            $mediaUrl ?? '',
            $status,
            $scheduled_at,
            json_encode($channelIds, JSON_UNESCAPED_UNICODE),
        ]);

        $postId = (int)$db->lastInsertId();

        // ارسال فوری نیز از همان atomic claim مسیر صف استفاده می‌کند؛ ارسال مستقیم
        // بدون رزرو سهمیه در برابر درخواست‌های همزمان امن نیست.
        if ($send_type === 'instant') {
            $db->prepare("UPDATE posts SET status = 'queued' WHERE id = ? AND tenant_id = ? AND status = 'draft'")->execute([$postId, $tenant_id]);
            if (!Quota::reservePost($tenant_id, $postId)) {
                $db->prepare("UPDATE posts SET status = 'failed' WHERE id = ? AND tenant_id = ? AND status = 'queued'")->execute([$postId, $tenant_id]);
                MobileApiResponse::error('سهمیه ارسال پست شما به پایان رسیده یا درخواست همزمان دیگری در حال پردازش است.', 409);
            }

            $res = Sender::sendPostToChannels(
                $tenant_id,
                $channelIds,
                $title,
                $content,
                $mediaUrl ?? '',
                $postId
            );

            if ($res['success']) {
                Quota::consumePostQuota($tenant_id, $postId);
            } else {
                $db->prepare("UPDATE posts SET status = 'failed' WHERE id = ? AND tenant_id = ? AND status = 'sending'")->execute([$postId, $tenant_id]);
            }
        }

        // دریافت پست ایجادشده برای پاسخ
        $stmt = $db->prepare("SELECT * FROM posts WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$postId, $tenant_id]);
        $post = $stmt->fetch();
        $post['click_count'] = 0;

        MobileApiResponse::success($post, 'پست با موفقیت ایجاد شد.');
    }

    /**
     * دریافت اطلاعات یک پست
     * GET /api/v1/posts/{id} (auth)
     *
     * @param string $id شناسه پست از مسیر
     */
    public function show(string $id): void {
        $tenant_id = $this->userId();
        $db = $this->db();
        $postId = (int)$id;

        $stmt = $db->prepare("SELECT * FROM posts WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$postId, $tenant_id]);
        $post = $stmt->fetch();

        if (!$post) {
            MobileApiResponse::notFound('پست مورد نظر یافت نشد.');
        }

        // دریافت تعداد کلیک
        $stmt = $db->prepare("SELECT COUNT(*) as click_count FROM clicks_log WHERE post_id = ?");
        $stmt->execute([$postId]);
        $clickRow = $stmt->fetch();
        $post['click_count'] = (int)$clickRow['click_count'];

        MobileApiResponse::success($post);
    }

    /**
     * لغو پست
     * POST /api/v1/posts/{id}/cancel (auth)
     *
     * فقط پست‌هایی با وضعیت scheduled، queued یا draft قابل لغو هستند.
     *
     * @param string $id شناسه پست از مسیر
     */
    public function cancel(string $id): void {
        $tenant_id = $this->userId();
        $db = $this->db();
        $postId = (int)$id;

        // بررسی وجود پست و مالکیت
        $stmt = $db->prepare("SELECT * FROM posts WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$postId, $tenant_id]);
        $post = $stmt->fetch();

        if (!$post) {
            MobileApiResponse::notFound('پست مورد نظر یافت نشد.');
        }

        // بررسی وضعیت — فقط وضعیت‌های قابل لغو
        $allowedStatuses = ['scheduled', 'queued', 'draft'];
        if (!in_array($post['status'], $allowedStatuses)) {
            MobileApiResponse::error('این پست در وضعیت فعلی قابل لغو نیست.', 400);
        }

        // حذف پست
        $placeholders = implode(',', array_fill(0, count($allowedStatuses), '?'));
        $params = array_merge([$postId, $tenant_id], $allowedStatuses);
        $stmt = $db->prepare(
            "DELETE FROM posts WHERE id = ? AND tenant_id = ? AND status IN ({$placeholders})"
        );
        $stmt->execute($params);

        if ($stmt->rowCount() > 0) {
            MobileApiResponse::success(null, 'پست با موفقیت لغو و حذف شد.');
        }

        MobileApiResponse::error('خطا در لغو پست. لطفاً دوباره تلاش کنید.');
    }

    /**
     * تلاش مجدد ارسال پست
     * POST /api/v1/posts/{id}/retry (auth)
     *
     * فقط پست‌هایی با وضعیت failed قابل تلاش مجدد هستند.
     *
     * @param string $id شناسه پست از مسیر
     */
    public function retry(string $id): void {
        $tenant_id = $this->userId();
        $db = $this->db();
        $postId = (int)$id;

        // بررسی وجود پست و مالکیت
        $stmt = $db->prepare("SELECT * FROM posts WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$postId, $tenant_id]);
        $post = $stmt->fetch();

        if (!$post) {
            MobileApiResponse::notFound('پست مورد نظر یافت نشد.');
        }

        // فقط پست‌های ناموفق قابل تلاش مجدد هستند
        if ($post['status'] !== 'failed') {
            MobileApiResponse::error('فقط پست‌های ناموفق قابل تلاش مجدد هستند.', 400);
        }

        // تغییر وضعیت به queued
        $stmt = $db->prepare(
            "UPDATE posts SET status = 'queued' WHERE id = ? AND tenant_id = ? AND status = 'failed'"
        );
        $stmt->execute([$postId, $tenant_id]);

        if ($stmt->rowCount() > 0) {
            // دریافت لیست کانال‌های هدف
            $channelIds = json_decode($post['target_channels'] ?? '[]', true);
            if (empty($channelIds) || !is_array($channelIds)) {
                $stmt2 = $db->prepare("SELECT id FROM channels WHERE tenant_id = ?");
                $stmt2->execute([$tenant_id]);
                $channelIds = $stmt2->fetchAll(\PDO::FETCH_COLUMN);
            }

            // رزرو اتمیک سهمیه و مالکیت پست؛ از race بین دو retry جلوگیری می‌کند.
            if (!Quota::reservePost($tenant_id, $postId)) {
                MobileApiResponse::error('سهمیه ارسال پست شما به پایان رسیده یا درخواست دیگری آن را در حال پردازش دارد.', 409);
            }

            // ارسال مجدد به کانال‌ها
            $res = Sender::sendPostToChannels(
                $tenant_id,
                $channelIds,
                $post['title'],
                $post['content'],
                $post['media_url'] ?? '',
                $postId
            );

            if ($res['success']) {
                Quota::consumePostQuota($tenant_id, $postId);
                MobileApiResponse::success(null, 'پست با موفقیت مجدداً ارسال شد.');
            } else {
                $db->prepare("UPDATE posts SET status = 'failed' WHERE id = ? AND tenant_id = ? AND status = 'sending'")->execute([$postId, $tenant_id]);
                $errors = [];
                foreach ($res['channels'] ?? [] as $ch) {
                    if (!$ch['success']) {
                        $errors[] = $ch['name'] . ': ' . $ch['message'];
                    }
                }
                MobileApiResponse::error('تلاش مجدد ناموفق بود: ' . implode('; ', $errors));
            }
        }

        MobileApiResponse::error('خطا در تلاش مجدد. لطفاً دوباره تلاش کنید.');
    }

    /**
     * تبدیل تاریخ جلالی (شمسی) به میلادی
     *
     * الگوریتم استاندارد تبدیل تقویم شمسی به میلادی
     *
     * @param int $jy سال جلالی
     * @param int $jm ماه جلالی (۱-۱۲)
     * @param int $jd روز جلالی
     * @return array [سال میلادی, ماه میلادی, روز میلادی]
     */
    private function jalaliToGregorian(int $jy, int $jm, int $jd): array {
        $g_days_in_month = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

        $jy -= 979;
        $jm -= 1;
        $jd -= 1;

        $j_days_no = 365 * $jy + (int)($jy / 33) * 8 + (int)(($jy % 33 + 3) / 4) + $jd + (($jm <= 6) ? ($jm * 31) : (($jm - 6) * 30 + 186));

        $gy = 1600 + 400 * (int)($j_days_no / 146097);
        $j_days_no %= 146097;

        $is_leap = ($j_days_no >= 36525);
        if ($is_leap) $j_days_no--;

        $gy += 100 * (int)($j_days_no / 36524);
        $j_days_no %= 36524;

        if ($j_days_no >= 365) $j_days_no++;

        $gy += 4 * (int)($j_days_no / 1461);
        $j_days_no %= 1461;

        if ($j_days_no >= 366) {
            $gy += (int)(($j_days_no - 1) / 365);
            $j_days_no = ($j_days_no - 1) % 365;
        }

        for ($i = 0; $i < 11 && $j_days_no >= $g_days_in_month[$i]; $i++) {
            $j_days_no -= $g_days_in_month[$i];
        }

        $gm = $i + 1;
        $gd = $j_days_no + 1;

        return [$gy, $gm, $gd];
    }
}
