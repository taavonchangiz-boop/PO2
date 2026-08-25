<?php
namespace WHCM\Api\Controllers;

use WHCM\Api\MobileApiResponse;
use WHCM\Domain\Notification;

/**
 * کنترلر API اعلان‌های کاربر
 *
 * شامل: دریافت لیست اعلان‌ها، علامت‌گذاری خوانده‌شده، علامت‌گذاری همه
 *
 * @package WHCM\Api\Controllers
 */
class NotificationApiController extends \WHCM\Api\MobileApiController {

    /**
     * دریافت لیست اعلان‌های کاربر
     * GET /api/v1/notifications (auth)
     *
     * Query params: limit (default 20), offset (default 0)
     */
    public function index(): void {
        $userId = $this->userId();

        $limit  = (int)($this->get('limit') ?? 20);
        $offset = (int)($this->get('offset') ?? 0);

        if ($limit < 1) $limit = 20;
        if ($limit > 100) $limit = 100;
        if ($offset < 0) $offset = 0;

        $notifications = Notification::getUserNotifications($userId, $limit, $offset);
        $unreadCount   = Notification::getUnreadCount($userId);

        MobileApiResponse::success([
            'notifications' => $notifications,
            'unread_count'  => $unreadCount,
        ]);
    }

    /**
     * علامت‌گذاری یک اعلان به‌عنوان خوانده‌شده
     * POST /api/v1/notifications/{id}/read (auth)
     *
     * @param string $id شناسه اعلان از مسیر
     */
    public function markRead(string $id): void {
        $userId        = $this->userId();
        $notificationId = (int)$id;

        if ($notificationId <= 0) {
            MobileApiResponse::error('شناسه اعلان نامعتبر است.', 400);
        }

        $success = Notification::markAsRead($notificationId, $userId);

        if (!$success) {
            MobileApiResponse::error('اعلان یافت نشد یا قبلاً خوانده شده است.', 404);
        }

        $remainingCount = Notification::getUnreadCount($userId);

        MobileApiResponse::success([
            'remaining_unread' => $remainingCount,
        ], 'اعلان به‌عنوان خوانده‌شده علامت‌گذاری شد.');
    }

    /**
     * علامت‌گذاری تمام اعلان‌ها به‌عنوان خوانده‌شده
     * POST /api/v1/notifications/read-all (auth)
     */
    public function markAllRead(): void {
        $userId = $this->userId();

        $affected = Notification::markAllAsRead($userId);

        MobileApiResponse::success([
            'marked_count' => $affected,
        ], 'تمام اعلان‌ها به‌عنوان خوانده‌شده علامت‌گذاری شدند.');
    }
}
