<?php
namespace WHCM\Api\Controllers;

use WHCM\Api\MobileApiResponse;
use WHCM\Domain\LinkTracker;

/**
 * کنترلر API تحلیل‌ها و آمار
 *
 * شامل: آمار لینک‌ها، جزئیات کلیک لینک‌ها
 *
 * @package WHCM\Api\Controllers
 */
class AnalyticsApiController extends \WHCM\Api\MobileApiController {

    /**
     * دریافت آمار کلی لینک‌ها
     * GET /api/v1/analytics/links (auth)
     */
    public function linkStats(): void {
        $tenantId = $this->userId();
        $db       = $this->db();

        $stmt = $db->prepare("SELECT * FROM link_tracking WHERE tenant_id = ? ORDER BY id DESC");
        $stmt->execute([$tenantId]);
        $links = $stmt->fetchAll();

        // برای هر لینک تعداد کلیک و کلیک یکتا
        foreach ($links as &$link) {
            $linkId = (int)$link['id'];

            $stmt = $db->prepare("SELECT COUNT(*) as clicks, COUNT(DISTINCT ip_address) as unique_clicks FROM link_clicks WHERE link_id = ?");
            $stmt->execute([$linkId]);
            $clickRow = $stmt->fetch();

            $link['total_clicks']    = (int)$clickRow['clicks'];
            $link['unique_clicks']   = (int)$clickRow['unique_clicks'];
        }
        unset($link);

        MobileApiResponse::success($links);
    }

    /**
     * دریافت جزئیات آمار یک لینک خاص
     * GET /api/v1/analytics/links/{id} (auth)
     *
     * @param string $id شناسه لینک از مسیر
     */
    public function linkStatsDetail(string $id): void {
        $tenantId = $this->userId();
        $db       = $this->db();
        $linkId   = (int)$id;

        // دریافت اطلاعات لینک
        $stmt = $db->prepare("SELECT * FROM link_tracking WHERE id = ? AND tenant_id = ? LIMIT 1");
        $stmt->execute([$linkId, $tenantId]);
        $link = $stmt->fetch();

        if (!$link) {
            MobileApiResponse::notFound('لینک مورد نظر یافت نشد.');
        }

        // آمار کلی
        $stmt = $db->prepare("SELECT COUNT(*) as clicks, COUNT(DISTINCT ip_address) as unique_clicks FROM link_clicks WHERE link_id = ?");
        $stmt->execute([$linkId]);
        $stats = $stmt->fetch();

        $link['total_clicks']  = (int)$stats['clicks'];
        $link['unique_clicks'] = (int)$stats['unique_clicks'];

        // شکست روزانه کلیک‌ها
        $stmt = $db->prepare("
            SELECT DATE(created_at) as date,
                   COUNT(*) as clicks,
                   COUNT(DISTINCT ip_address) as unique_clicks
            FROM link_clicks
            WHERE link_id = ?
            GROUP BY DATE(created_at)
            ORDER BY date DESC
        ");
        $stmt->execute([$linkId]);
        $dailyBreakdown = $stmt->fetchAll();

        MobileApiResponse::success([
            'link'           => $link,
            'daily_breakdown' => $dailyBreakdown,
        ]);
    }
}
