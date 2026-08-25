<?php
namespace WHCM\Domain;

use WHCM\Core\Bootstrap;

/**
 * مدیریت بررسی سهمیه، اشتراک‌ها و محدودیت‌های پلن برای هر مستاجر
 *
 * @package WHCM\Domain
 */
class Quota {

    /**
     * استخراج وضعیت کامل اشتراک و سهمیه‌های یک مستاجر
     *
     * @param int $tenant_id شناسه کاربر
     * @return array
     */
    public static function getTenantQuota(int $tenant_id): array {
        $db = Bootstrap::getDB();
        $now = date('Y-m-d H:i:s');

        // ۱. دریافت اشتراک فعال کاربر (همراه با اطلاعات پلن مربوطه)
        $stmt = $db->prepare("
            SELECT s.*, p.title as plan_title, p.price, p.max_channels, p.max_posts, p.features 
            FROM subscriptions s 
            JOIN plans p ON s.plan_id = p.id 
            WHERE s.user_id = ? AND s.status = 'active' AND s.end_date > ? 
            ORDER BY s.id DESC LIMIT 1
        ");
        $stmt->execute([$tenant_id, $now]);
        $sub = $stmt->fetch();

        // اگر اشتراک فعال پیدا نشد، کاربر فاقد دسترسی سیستمی است
        if (!$sub) {
            return [
                'has_active_sub' => false,
                'plan_title' => 'بدون اشتراک فعال',
                'end_date' => null,
                'max_channels' => 0,
                'max_posts' => 0,
                'used_channels' => 0,
                'used_posts' => 0,
                'can_add_channel' => false,
                'can_send_post' => false,
                'features' => []
            ];
        }

        // ۲. شمارش تعداد کانال‌های متصل فعلی کاربر
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM channels WHERE tenant_id = ?");
        $stmt->execute([$tenant_id]);
        $used_channels = (int)$stmt->fetch()['cnt'];

        // ۳. شمارش تعداد پست‌های ارسالی کاربر در طول دوره اشتراک فعلی
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM posts WHERE tenant_id = ? AND status IN ('sent', 'sending') AND created_at >= ?");
        $stmt->execute([$tenant_id, $sub['start_date']]);
        $used_posts = (int)$stmt->fetch()['cnt'];

        $features = json_decode($sub['features'] ?? '{}', true);

        // ۴. تعیین شرایط و دسترسی‌ها
        $max_channels = (int)$sub['max_channels'];
        $max_posts = (int)$sub['max_posts'];

        $can_add_channel = $used_channels < $max_channels;
        
        // اگر سهمیه پست 0 باشد یعنی نامحدود است
        $can_send_post = ($max_posts === 0) || ($used_posts < $max_posts);

        return [
            'has_active_sub' => true,
            'plan_id' => (int)$sub['plan_id'],
            'plan_title' => $sub['plan_title'],
            'end_date' => $sub['end_date'],
            'max_channels' => $max_channels,
            'max_posts' => $max_posts,
            'used_channels' => $used_channels,
            'used_posts' => $used_posts,
            'can_add_channel' => $can_add_channel,
            'can_send_post' => $can_send_post,
            'features' => $features
        ];
    }

    /**
     * ثبت مصرف یک پست (بروزرسانی وضعیت پست به sent در صورت تایید سهمیه)
     *
     * @param int $tenant_id
     * @param int $post_id
     * @return bool
     */
    /**
     * Atomically reserve one post quota unit while claiming a queued/scheduled post.
     * The status transition to sending is the reservation, so concurrent workers cannot
     * both observe the same remaining quota and proceed.
     */
    public static function reservePost(int $tenant_id, int $post_id): bool {
        $db = Bootstrap::getDB();
        $driver = Bootstrap::getConfig('database.driver', 'sqlite');
        $now = date('Y-m-d H:i:s');
        $started = false;

        try {
            // Lock the subscription row on MySQL. SQLite has no row-level FOR UPDATE,
            // so BEGIN IMMEDIATE serializes the short quota reservation transaction.
            if ($db->inTransaction()) {
                throw new \RuntimeException('reservePost must not run inside an outer transaction.');
            }
            if ($driver === 'sqlite') {
                $db->exec('BEGIN IMMEDIATE');
                $started = true;
            } else {
                $db->beginTransaction();
                $started = true;
            }

            $sql = "SELECT s.id, s.start_date, p.max_posts
                    FROM subscriptions s
                    JOIN plans p ON p.id = s.plan_id
                    WHERE s.user_id = ? AND s.status = 'active' AND s.end_date > ?
                    ORDER BY s.id DESC LIMIT 1";
            if ($driver === 'mysql') $sql .= ' FOR UPDATE';
            $stmt = $db->prepare($sql);
            $stmt->execute([$tenant_id, $now]);
            $sub = $stmt->fetch();
            if (!$sub) {
                $db->rollBack();
                return false;
            }

            $maxPosts = (int)$sub['max_posts'];
            if ($maxPosts > 0) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM posts
                                      WHERE tenant_id = ?
                                        AND status IN ('sent','sending')
                                        AND created_at >= ?");
                $stmt->execute([$tenant_id, $sub['start_date']]);
                if ((int)$stmt->fetchColumn() >= $maxPosts) {
                    $db->rollBack();
                    return false;
                }
            }

            $stmt = $db->prepare("UPDATE posts SET status = 'sending'
                                  WHERE id = ? AND tenant_id = ? AND status IN ('queued','scheduled')");
            $stmt->execute([$post_id, $tenant_id]);
            $ok = $stmt->rowCount() === 1;
            if ($ok) {
                $db->commit();
            } else {
                $db->rollBack();
            }
            return $ok;
        } catch (\Throwable $e) {
            if ($started && $db->inTransaction()) $db->rollBack();
            error_log('[Postyar] quota reservation failed: ' . $e->getMessage());
            return false;
        }
    }

    public static function consumePostQuota(int $tenant_id, int $post_id): bool {
        // Backward-compatible API: callers that already marked a post as sending can
        // finalize it. New workers should use reservePost() before external delivery.
        $db = Bootstrap::getDB();
        $stmt = $db->prepare("UPDATE posts SET status = 'sent' WHERE id = ? AND tenant_id = ? AND status = 'sending'");
        return $stmt->execute([$post_id, $tenant_id]) && $stmt->rowCount() === 1;
    }
}
