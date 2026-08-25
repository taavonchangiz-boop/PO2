<?php
namespace WHCM\Domain;

use WHCM\Core\Bootstrap;

class LinkTracker {

    public static function processContent(string $content, int $postId, int $channelId, int $tenantId): string {
        $appUrl = rtrim(Bootstrap::getConfig('app.url', ''), '/');
        $db = Bootstrap::getDB();
        $pattern = '/https?:\/\/[^\s<>"\']+/i';

        $content = preg_replace_callback($pattern, function($matches) use ($db, $appUrl, $postId, $channelId, $tenantId) {
            $originalUrl = $matches[0];
            $code = self::generateUniqueCode($db);
            try {
                $stmt = $db->prepare("INSERT INTO link_tracking (code, original_url, post_id, channel_id, tenant_id) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$code, $originalUrl, $postId, $channelId, $tenantId]);
            } catch (\Exception $e) {
                return $originalUrl;
            }
            return $appUrl . '/go/' . $code;
        }, $content);

        return $content;
    }

    public static function handleClick(string $code): ?array {
        $db = Bootstrap::getDB();
        $stmt = $db->prepare("SELECT * FROM link_tracking WHERE code = ? LIMIT 1");
        $stmt->execute([$code]);
        $link = $stmt->fetch();
        if (!$link) return null;

        $linkId = (int)$link['id'];
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $referer = $_SERVER['HTTP_REFERER'] ?? '';

        $isUnique = 1;
        try {
            $stmt = $db->prepare("SELECT 1 FROM link_clicks WHERE link_id = ? AND ip_address = ? LIMIT 1");
            $stmt->execute([$linkId, $ip]);
            if ($stmt->fetch()) $isUnique = 0;
        } catch (\Exception $e) {}

        try {
            $stmt = $db->prepare("INSERT INTO link_clicks (link_id, ip_address, user_agent, referer, is_unique) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$linkId, $ip, $userAgent, $referer, $isUnique]);
        } catch (\Exception $e) {}

        try {
            $stmt = $db->prepare("UPDATE link_tracking SET total_clicks = total_clicks + 1, unique_clicks = unique_clicks + ? WHERE id = ?");
            $stmt->execute([$isUnique, $linkId]);
        } catch (\Exception $e) {}

        return ['original_url' => $link['original_url'], 'link_id' => $linkId];
    }

    public static function getPostLinks(int $postId): array {
        $db = Bootstrap::getDB();
        $stmt = $db->prepare("SELECT lt.*, (SELECT COUNT(*) FROM link_clicks lc WHERE lc.link_id = lt.id) as click_count, (SELECT COUNT(*) FROM link_clicks lc WHERE lc.link_id = lt.id AND lc.is_unique = 1) as unique_count FROM link_tracking lt WHERE lt.post_id = ? ORDER BY lt.created_at DESC");
        $stmt->execute([$postId]);
        return $stmt->fetchAll();
    }

    public static function getUserLinkStats(int $tenantId, ?int $postId = null, ?string $dateFrom = null, ?string $dateTo = null): array {
        $db = Bootstrap::getDB();
        $params = [$tenantId];
        $sql = "SELECT lt.* FROM link_tracking lt WHERE lt.tenant_id = ?";
        if ($postId !== null) { $sql .= " AND lt.post_id = ?"; $params[] = $postId; }
        if ($dateFrom) { $sql .= " AND lt.created_at >= ?"; $params[] = $dateFrom; }
        if ($dateTo) { $sql .= " AND lt.created_at <= ?"; $params[] = $dateTo; }
        $sql .= " ORDER BY lt.total_clicks DESC LIMIT 50";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $links = $stmt->fetchAll();

        $totalClicks = 0; $uniqueClicks = 0;
        foreach ($links as $link) {
            $totalClicks += (int)$link['total_clicks'];
            $uniqueClicks += (int)$link['unique_clicks'];
        }
        return ['total_clicks' => $totalClicks, 'unique_clicks' => $uniqueClicks, 'top_links' => $links, 'total_links' => count($links)];
    }

    public static function getDailyClicks(int $tenantId, int $days = 30): array {
        $db = Bootstrap::getDB();
        $driver = Bootstrap::getConfig('database.driver', 'sqlite');
        $params = [$tenantId, $days];
        if ($driver === 'mysql') {
            $sql = "SELECT DATE(lc.created_at) as date, COUNT(*) as clicks, SUM(lc.is_unique) as unique_clicks FROM link_clicks lc JOIN link_tracking lt ON lc.link_id = lt.id WHERE lt.tenant_id = ? AND lc.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY DATE(lc.created_at) ORDER BY date ASC";
        } else {
            $sql = "SELECT DATE(lc.created_at) as date, COUNT(*) as clicks, SUM(lc.is_unique) as unique_clicks FROM link_clicks lc JOIN link_tracking lt ON lc.link_id = lt.id WHERE lt.tenant_id = ? AND lc.created_at >= datetime('now', '-' || ? || ' days') GROUP BY DATE(lc.created_at) ORDER BY date ASC";
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private static function generateUniqueCode(\PDO $db): string {
        for ($i = 0; $i < 10; $i++) {
            $code = strtoupper(bin2hex(random_bytes(4)));
            $stmt = $db->prepare("SELECT 1 FROM link_tracking WHERE code = ? LIMIT 1");
            $stmt->execute([$code]);
            if (!$stmt->fetch()) return $code;
        }
        return strtoupper(substr(bin2hex(random_bytes(8)), 0, 8));
    }
}