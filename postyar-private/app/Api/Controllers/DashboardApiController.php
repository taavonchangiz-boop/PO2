<?php
namespace WHCM\Api\Controllers;

use WHCM\Api\MobileApiResponse;
use WHCM\Api\MobileApiAuth;
use WHCM\Api\MobileApiRouter;
use WHCM\Core\Bootstrap;
use WHCM\Domain\Quota;
use WHCM\Domain\ChannelManager;
use WHCM\Domain\Notification;
use WHCM\Domain\Referral;
use WHCM\Domain\Wallet;

/**
 * کنترلر داشبورد API موبایل
 *
 * شامل: بوت‌استرپ اولیه (تمام داده‌های داشبورد) و همگام‌سازی دوره‌ای
 *
 * @package WHCM\Api\Controllers
 */
class DashboardApiController extends \WHCM\Api\MobileApiController {

    /**
     * بوت‌استرپ — دریافت تمام داده‌های اولیه پس از ورود
     * GET /api/v1/bootstrap
     *
     * مهم‌ترین endpoint: اندروید پس از ورود یک‌بار این را فراخوانی می‌کند
     * تا تمام داده‌های لازم برای ساخت رابط کاربری را دریافت کند.
     */
    public function bootstrap(): void {
        $tenant_id = $this->userId();
        $db = $this->db();

        // کاربر فعلی
        $user = $this->user();

        // سهمیه و وضعیت اشتراک
        $quota = Quota::getTenantQuota($tenant_id);

        // کانال‌های متصل
        $channels = ChannelManager::getTenantChannels($tenant_id);

        // پست‌ها به‌همراه تعداد کلیک
        $stmt = $db->prepare("SELECT * FROM posts WHERE tenant_id = ? ORDER BY id DESC LIMIT 50");
        $stmt->execute([$tenant_id]);
        $posts = $stmt->fetchAll();

        // دریافت تعداد کلیک برای هر پست از clicks_log
        $postIds = array_column($posts, 'id');
        $clickCounts = [];
        if (!empty($postIds)) {
            $placeholders = implode(',', array_fill(0, count($postIds), '?'));
            $stmt = $db->prepare("SELECT post_id, COUNT(*) as click_count FROM clicks_log WHERE post_id IN ({$placeholders}) GROUP BY post_id");
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

        // اعلان‌ها: خوانده‌نشده‌های اخیر + تعداد کل
        $notifications = Notification::getRecentUnread($tenant_id, 20);
        $unreadCount = Notification::getUnreadCount($tenant_id);

        // پاسخ‌های خودکار
        $stmt = $db->prepare("
            SELECT ar.*, c.name as channel_name, c.platform as channel_platform
            FROM auto_replies ar
            JOIN channels c ON ar.channel_id = c.id
            WHERE ar.tenant_id = ?
            ORDER BY ar.id DESC
        ");
        $stmt->execute([$tenant_id]);
        $autoReplies = $stmt->fetchAll();

        // صندوق ورودی
        $stmt = $db->prepare("
            SELECT i.*, c.name as channel_name
            FROM inbox i
            JOIN channels c ON i.channel_id = c.id
            WHERE i.tenant_id = ?
            ORDER BY i.id DESC
            LIMIT 15
        ");
        $stmt->execute([$tenant_id]);
        $inbox = $stmt->fetchAll();

        // تیکت‌ها
        $stmt = $db->prepare("SELECT * FROM tickets WHERE user_id = ? ORDER BY id DESC LIMIT 50");
        $stmt->execute([$tenant_id]);
        $tickets = $stmt->fetchAll();

        // پلن‌ها
        $stmt = $db->query("SELECT * FROM plans ORDER BY price ASC");
        $plans = $stmt->fetchAll();

        // پیشنهادات تخفیف فعال
        $stmt = $db->prepare("
            SELECT do.*, p.title as plan_title
            FROM discount_offers do
            JOIN plans p ON do.plan_id = p.id
            WHERE do.user_id = ? AND do.used = 0
        ");
        $stmt->execute([$tenant_id]);
        $offers = $stmt->fetchAll();

        // تاریخچه اشتراک‌ها
        $stmt = $db->prepare("
            SELECT s.*, p.title as plan_title, p.price as plan_price
            FROM subscriptions s
            LEFT JOIN plans p ON s.plan_id = p.id
            WHERE s.user_id = ?
            ORDER BY s.id DESC
            LIMIT 20
        ");
        $stmt->execute([$tenant_id]);
        $subscriptionHistory = $stmt->fetchAll();

        // تاریخچه پرداخت‌ها
        $stmt = $db->prepare("
            SELECT pay.*, p.title as plan_title
            FROM payments pay
            LEFT JOIN plans p ON pay.plan_id = p.id
            WHERE pay.user_id = ?
            ORDER BY pay.id DESC
            LIMIT 20
        ");
        $stmt->execute([$tenant_id]);
        $paymentHistory = $stmt->fetchAll();

        // تنظیمات کاربر
        $stmt = $db->prepare("SELECT key_name, key_value FROM settings WHERE tenant_id = ?");
        $stmt->execute([$tenant_id]);
        $settingsRows = $stmt->fetchAll();
        $settings = [];
        foreach ($settingsRows as $row) {
            $settings[$row['key_name']] = $row['key_value'];
        }

        // تنظیمات پاسخگو خودکار
        $stmt = $db->prepare("SELECT key_name, key_value FROM settings WHERE tenant_id = ? AND key_name LIKE 'responder_enabled_%'");
        $stmt->execute([$tenant_id]);
        $responderSettingsRows = $stmt->fetchAll();
        $responderSettings = [];
        foreach ($responderSettingsRows as $row) {
            $responderSettings[$row['key_name']] = $row['key_value'];
        }

        // دسته‌بندی تیکت‌ها
        $stmt = $db->query("SELECT * FROM ticket_categories ORDER BY sort_order ASC, id ASC");
        $ticketCategories = $stmt->fetchAll();

        // اعلان سراسری
        $stmt = $db->prepare("SELECT key_value FROM settings WHERE tenant_id = 0 AND key_name = 'global_announcement' LIMIT 1");
        $stmt->execute();
        $announcementRow = $stmt->fetch();
        $announcement = null;
        if ($announcementRow) {
            $announcement = json_decode($announcementRow['key_value'], true);
        }

        // بررسی خوانده‌نبودن اعلان سراسری
        $lastReadAnnouncementId = (int)($settings['last_read_announcement_id'] ?? 0);
        $announcementUnread = false;
        if ($announcement && !empty($announcement['id'])) {
            $announcementUnread = ((int)$announcement['id'] > $lastReadAnnouncementId);
        }

        // اطلاعات زیرمجموعه‌گیری
        $referralCode = Referral::getUserReferralCode($tenant_id);
        $referralStats = Referral::getReferralStats($tenant_id);

        // موجودی کیف پول
        $walletBalance = Wallet::getBalance($tenant_id);

        MobileApiResponse::success([
            'user'                  => $user,
            'quota'                 => $quota,
            'channels'              => $channels,
            'posts'                 => $posts,
            'notifications'         => $notifications,
            'unread_count'          => $unreadCount,
            'auto_replies'          => $autoReplies,
            'inbox'                 => $inbox,
            'tickets'               => $tickets,
            'plans'                 => $plans,
            'offers'                => $offers,
            'subscription_history'  => $subscriptionHistory,
            'payment_history'       => $paymentHistory,
            'settings'              => $settings,
            'responder_settings'    => $responderSettings,
            'ticket_categories'     => $ticketCategories,
            'announcement'          => $announcement,
            'announcement_unread'   => $announcementUnread,
            'referral_info'         => [
                'code'  => $referralCode,
                'total' => $referralStats['total'],
            ],
            'wallet_balance'        => $walletBalance,
        ]);
    }

    /**
     * همگام‌سازی دوره‌ای — دریافت تغییرات اخیر
     * GET /api/v1/sync?since=<unix_timestamp>
     *
     * اندروید این endpoint را به‌صورت دوره‌ای فراخوانی می‌کند
     * تا تغییرات جدید (اعلان‌ها، کانال‌ها، پست‌ها و سهمیه) را دریافت کند.
     */
    public function sync(): void {
        $tenant_id = $this->userId();
        $db = $this->db();

        $since = $this->get('since');

        // اعلان‌های اخیر (تمام، نه فقط خوانده‌نشده — برای همگام‌سازی کامل)
        $notifications = Notification::getUserNotifications($tenant_id, 20, 0);

        // تعداد اعلان‌های خوانده‌نشده
        $unreadCount = Notification::getUnreadCount($tenant_id);

        // کانال‌ها (برای دریافت تغییرات احتمالی)
        $channels = ChannelManager::getTenantChannels($tenant_id);

        // اگر پارامتر since ارائه شده، فقط تغییرات بعد از آن زمان
        $recentPosts = [];
        if ($since !== null) {
            $sinceDatetime = date('Y-m-d H:i:s', (int)$since);
            $stmt = $db->prepare("SELECT * FROM posts WHERE tenant_id = ? AND created_at > ? ORDER BY id DESC LIMIT 20");
            $stmt->execute([$tenant_id, $sinceDatetime]);
            $recentPosts = $stmt->fetchAll();
        } else {
            $stmt = $db->prepare("SELECT * FROM posts WHERE tenant_id = ? ORDER BY id DESC LIMIT 20");
            $stmt->execute([$tenant_id]);
            $recentPosts = $stmt->fetchAll();
        }

        // سهمیه
        $quota = Quota::getTenantQuota($tenant_id);

        // موجودی کیف پول
        $walletBalance = Wallet::getBalance($tenant_id);

        MobileApiResponse::success([
            'server_time'    => date('Y-m-d H:i:s'),
            'notifications'  => $notifications,
            'unread_count'   => $unreadCount,
            'channels'       => $channels,
            'recent_posts'   => $recentPosts,
            'quota'          => $quota,
            'wallet_balance' => $walletBalance,
        ]);
    }
}