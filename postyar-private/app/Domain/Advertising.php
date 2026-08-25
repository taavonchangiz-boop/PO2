<?php
namespace WHCM\Domain;

use WHCM\Core\Bootstrap;
use WHCM\Core\Logger;

/**
 * Wave R — advertising domain service.
 *
 * Security invariants:
 * - Only approved campaigns inside their time window are publicly visible.
 * - Destination URLs accept only http(s), tg and bale schemes.
 * - Raw IPs/user agents are never persisted; only HMAC fingerprints are stored.
 * - Unique telemetry is bounded to one event per campaign/fingerprint/type/24h.
 * - Aggregate counters are updated atomically/upserted for reporting at scale.
 */
final class Advertising
{
    private const STATUSES = ['pending', 'approved', 'rejected', 'paused', 'archived'];
    private const EVENT_IMPRESSION = 'impression';
    private const EVENT_CLICK = 'click';

    public static function validateDestination(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $url)) return false;
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme'])) return false;
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https', 'tg', 'bale'], true)) return false;
        if (in_array($scheme, ['http', 'https'], true) && empty($parts['host'])) return false;
        return true;
    }

    public static function create(int $ownerId, string $title, string $imageUrl, string $destinationUrl, string $startsAt, string $endsAt): int
    {
        if ($ownerId <= 0 || mb_strlen(trim($title)) < 2 || mb_strlen($title) > 180) {
            throw new \InvalidArgumentException('اطلاعات آگهی نامعتبر است.');
        }
        if ($imageUrl === '' || strlen($imageUrl) > 2048 || !self::validateDestination($destinationUrl)) {
            throw new \InvalidArgumentException('تصویر یا لینک مقصد آگهی نامعتبر است.');
        }
        $start = self::normalizeDate($startsAt);
        $end = self::normalizeDate($endsAt);
        if (!$start || !$end || strtotime($end) <= strtotime($start)) {
            throw new \InvalidArgumentException('بازه زمانی آگهی نامعتبر است.');
        }
        $db = Bootstrap::getDB();
        $stmt = $db->prepare('INSERT INTO ad_campaigns (owner_user_id,title,image_url,destination_url,status,starts_at,ends_at,created_at,updated_at) VALUES (?,?,?,?,\'pending\',?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)');
        $stmt->execute([$ownerId, trim($title), $imageUrl, trim($destinationUrl), $start, $end]);
        return (int)$db->lastInsertId();
    }

    public static function active(int $limit = 8): array
    {
        $limit = max(1, min($limit, 20));
        $db = Bootstrap::getDB();
        $stmt = $db->prepare("SELECT a.id,a.title,COALESCE(c.image_url,a.image_url) image_url,COALESCE(c.destination_url,a.destination_url) destination_url,a.starts_at,a.ends_at,COALESCE(c.body_text,'') body_text,COALESCE(c.sort_order,0) sort_order
                FROM ad_campaigns a LEFT JOIN ad_creatives c ON c.campaign_id=a.id AND c.is_active=1
                WHERE a.status='approved' AND a.payment_status='paid' AND a.starts_at <= CURRENT_TIMESTAMP AND a.ends_at > CURRENT_TIMESTAMP
                ORDER BY a.id DESC, sort_order ASC LIMIT {$limit}");
        $stmt->execute();
        return $stmt->fetchAll();
    }


    /** تبلیغات فعال یک جایگاه مشخص. */
    public static function activeForPlacement(string $placementCode, int $limit = 8): array
    {
        $placementCode=trim($placementCode); if($placementCode==='') return [];
        $limit=max(1,min($limit,20)); $db=Bootstrap::getDB();
        $stmt=$db->prepare("SELECT DISTINCT a.id,a.title,COALESCE(c.image_url,a.image_url) image_url,COALESCE(c.destination_url,a.destination_url) destination_url,a.starts_at,a.ends_at,COALESCE(c.body_text,'') body_text,COALESCE(c.sort_order,0) sort_order
            FROM ad_campaigns a
            JOIN ad_campaign_placements cp ON cp.campaign_id=a.id
            LEFT JOIN ad_creatives c ON c.campaign_id=a.id AND c.is_active=1
            WHERE cp.placement_code=? AND a.status='approved' AND a.payment_status='paid'
              AND a.starts_at <= CURRENT_TIMESTAMP AND a.ends_at > CURRENT_TIMESTAMP
            ORDER BY a.id DESC,sort_order ASC LIMIT {$limit}");
        $stmt->execute([$placementCode]); return $stmt->fetchAll();
    }

    public static function findPublic(int $id): ?array
    {
        if ($id <= 0) return null;
        $db = Bootstrap::getDB();
        $stmt = $db->prepare("SELECT a.id,a.title,COALESCE(c.image_url,a.image_url) image_url,COALESCE(c.destination_url,a.destination_url) destination_url,COALESCE(c.body_text,'') body_text FROM ad_campaigns a LEFT JOIN ad_creatives c ON c.campaign_id=a.id AND c.is_active=1 WHERE a.id=? AND a.status='approved' AND a.payment_status='paid' AND a.starts_at <= CURRENT_TIMESTAMP AND a.ends_at > CURRENT_TIMESTAMP ORDER BY COALESCE(c.sort_order,0) ASC LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function recordEvent(int $campaignId, string $type, ?int $userId = null): bool
    {
        if (!in_array($type, [self::EVENT_IMPRESSION, self::EVENT_CLICK], true)) return false;
        $campaign = self::findPublic($campaignId);
        if (!$campaign) return false;

        $ip = self::clientIp();
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);
        if (self::looksLikeBot($ua)) return false;

        $fingerprint = self::fingerprint($campaignId, $type, $userId, $ip, $ua);
        $ipHash = hash_hmac('sha256', $ip, self::secret());
        $uaHash = hash_hmac('sha256', $ua, self::secret());
        $db = Bootstrap::getDB();

        // Atomic uniqueness: fingerprint contains a day bucket and the DB unique index removes race conditions.
        $driver = Bootstrap::getConfig('database.driver','sqlite');
        $sql = $driver === 'mysql'
            ? 'INSERT IGNORE INTO ad_events (campaign_id,event_type,fingerprint_hash,user_id,ip_hash,user_agent_hash,occurred_at) VALUES (?,?,?,?,?,?,CURRENT_TIMESTAMP)'
            : 'INSERT OR IGNORE INTO ad_events (campaign_id,event_type,fingerprint_hash,user_id,ip_hash,user_agent_hash,occurred_at) VALUES (?,?,?,?,?,?,CURRENT_TIMESTAMP)';
        $stmt = $db->prepare($sql);
        $stmt->execute([$campaignId, $type, $fingerprint, $userId, $ipHash, $uaHash]);
        $unique = $stmt->rowCount() > 0;

        self::upsertDaily($campaignId, $type, $unique);
        return true;
    }

    public static function statsForOwner(int $ownerId, ?string $from = null, ?string $to = null): array
    {
        return self::stats($ownerId, $from, $to, false);
    }

    public static function statsForAdmin(?string $from = null, ?string $to = null): array
    {
        return self::stats(null, $from, $to, true);
    }

    private static function stats(?int $ownerId, ?string $from, ?string $to, bool $admin): array
    {
        $db = Bootstrap::getDB();
        $where = [];
        $params = [];
        if (!$admin) { $where[] = 'a.owner_user_id = ?'; $params[] = $ownerId; }
        if ($from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 's.stat_date >= ?'; $params[] = $from; }
        if ($to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where[] = 's.stat_date <= ?'; $params[] = $to; }
        $sql = 'SELECT a.id,a.title,a.status,a.starts_at,a.ends_at,a.owner_user_id,u.name owner_name,
                       COALESCE(SUM(s.impressions),0) impressions,
                       COALESCE(SUM(s.unique_impressions),0) unique_impressions,
                       COALESCE(SUM(s.clicks),0) clicks,
                       COALESCE(SUM(s.unique_clicks),0) unique_clicks
                FROM ad_campaigns a JOIN users u ON u.id=a.owner_user_id
                LEFT JOIN ad_daily_stats s ON s.campaign_id=a.id';
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' GROUP BY a.id,a.title,a.status,a.starts_at,a.ends_at,a.owner_user_id,u.name ORDER BY a.id DESC LIMIT 500';
        $stmt = $db->prepare($sql); $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function daily(int $campaignId, int $ownerId, ?string $from = null, ?string $to = null): array
    {
        $db = Bootstrap::getDB();
        $sql = 'SELECT s.stat_date,s.impressions,s.unique_impressions,s.clicks,s.unique_clicks
                FROM ad_daily_stats s JOIN ad_campaigns a ON a.id=s.campaign_id
                WHERE s.campaign_id=? AND a.owner_user_id=?';
        $params = [$campaignId, $ownerId];
        if ($from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $sql .= ' AND s.stat_date>=?'; $params[]=$from; }
        if ($to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $sql .= ' AND s.stat_date<=?'; $params[]=$to; }
        $sql .= ' ORDER BY s.stat_date DESC LIMIT 366';
        $stmt=$db->prepare($sql); $stmt->execute($params); return $stmt->fetchAll();
    }

    public static function adminCampaigns(int $limit = 100): array
    {
        $limit=max(1,min($limit,500));
        $db=Bootstrap::getDB();
        $stmt=$db->query("SELECT a.*,u.name owner_name,u.email owner_email FROM ad_campaigns a JOIN users u ON u.id=a.owner_user_id ORDER BY a.id DESC LIMIT {$limit}");
        return $stmt->fetchAll();
    }

    public static function ownerCampaigns(int $ownerId, int $limit = 100): array
    {
        $limit=max(1,min($limit,200));
        $db=Bootstrap::getDB();
        $stmt=$db->prepare("SELECT a.*,COALESCE(SUM(s.impressions),0) impressions,COALESCE(SUM(s.unique_impressions),0) unique_impressions,COALESCE(SUM(s.clicks),0) clicks,COALESCE(SUM(s.unique_clicks),0) unique_clicks FROM ad_campaigns a LEFT JOIN ad_daily_stats s ON s.campaign_id=a.id WHERE a.owner_user_id=? GROUP BY a.id ORDER BY a.id DESC LIMIT {$limit}");
        $stmt->execute([$ownerId]); return $stmt->fetchAll();
    }

    public static function exportCsv(?int $ownerId = null, bool $admin = false, ?string $from = null, ?string $to = null): string
    {
        $rows = $admin ? self::statsForAdmin($from, $to) : self::statsForOwner((int)$ownerId, $from, $to);
        $fp = fopen('php://temp', 'w+');
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, ['id','title','owner_user_id','owner_name','status','impressions','unique_impressions','clicks','unique_clicks','starts_at','ends_at']);
        foreach ($rows as $r) { fputcsv($fp, [$r['id'],$r['title'],$r['owner_user_id'],$r['owner_name']??'', $r['status'],$r['impressions'],$r['unique_impressions'],$r['clicks'],$r['unique_clicks'],$r['starts_at'],$r['ends_at']]); }
        rewind($fp); $csv=stream_get_contents($fp); fclose($fp); return (string)$csv;
    }

    public static function pruneEvents(int $days = 90): int
    {
        $days=max(30,min($days,730)); $db=Bootstrap::getDB();
        $cutoff=date('Y-m-d H:i:s',time()-($days*86400));
        $stmt=$db->prepare('DELETE FROM ad_events WHERE occurred_at < ?'); $stmt->execute([$cutoff]); return $stmt->rowCount();
    }

    public static function deleteCampaign(int $id): bool
    {
        if($id<=0) return false;
        $db=Bootstrap::getDB();
        try{
            $db->beginTransaction();
            $stmt=$db->prepare('DELETE FROM ad_campaign_placements WHERE campaign_id=?'); $stmt->execute([$id]);
            $stmt=$db->prepare('DELETE FROM ad_creatives WHERE campaign_id=?'); $stmt->execute([$id]);
            $stmt=$db->prepare('DELETE FROM ad_campaigns WHERE id=?'); $stmt->execute([$id]);
            $ok=$stmt->rowCount()>0;
            $db->commit();
            return $ok;
        }catch(\Throwable $e){
            if($db->inTransaction()) $db->rollBack();
            Logger::warning('ad_campaign_delete_failed',['campaign_id'=>$id,'reason'=>$e->getMessage()]);
            return false;
        }
    }

    public static function setStatus(int $campaignId, string $status, int $adminId): bool
    {
        if (!in_array($status, self::STATUSES, true) || $campaignId<=0 || $adminId<=0) return false;
        $db=Bootstrap::getDB();
        if ($status === 'approved') {
            // New paid workflow: an order-linked campaign can never be activated before payment verification.
            $stmt=$db->prepare("UPDATE ad_campaigns SET status='approved',approved_at=CURRENT_TIMESTAMP,approved_by=?,activation_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ('pending','paused') AND (order_id IS NULL OR payment_status='paid')");
            $stmt->execute([$adminId,$campaignId]);
        } else {
            $stmt=$db->prepare('UPDATE ad_campaigns SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
            $stmt->execute([$status,$campaignId]);
        }
        return $stmt->rowCount() > 0;
    }

    private static function upsertDaily(int $campaignId, string $type, bool $unique): void
    {
        $db=Bootstrap::getDB();
        $date=date('Y-m-d');
        $isImp=$type===self::EVENT_IMPRESSION;
        $column=$isImp?'impressions':'clicks'; $uniqueColumn=$isImp?'unique_impressions':'unique_clicks';
        if (Bootstrap::getConfig('database.driver','sqlite') === 'mysql') {
            $sql="INSERT INTO ad_daily_stats(campaign_id,stat_date,{$column},{$uniqueColumn},created_at,updated_at) VALUES(?,?,1,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE {$column}={$column}+1, {$uniqueColumn}={$uniqueColumn}+?, updated_at=CURRENT_TIMESTAMP";
            $stmt=$db->prepare($sql); $stmt->execute([$campaignId,$date,$unique?1:0,$unique?1:0]);
        } else {
            $stmt=$db->prepare("INSERT INTO ad_daily_stats(campaign_id,stat_date,{$column},{$uniqueColumn}) VALUES(?,?,1,?) ON CONFLICT(campaign_id,stat_date) DO UPDATE SET {$column}={$column}+1, {$uniqueColumn}={$uniqueColumn}+excluded.{$uniqueColumn}, updated_at=CURRENT_TIMESTAMP");
            $stmt->execute([$campaignId,$date,$unique?1:0]);
        }
    }

    private static function normalizeDate(string $value): ?string
    {
        $value=trim($value); $ts=strtotime($value); if (!$ts) return null;
        return date('Y-m-d H:i:s',$ts);
    }
    private static function secret(): string
    {
        $s=(string)Bootstrap::getConfig('security.salt','');
        return $s!==''?$s:hash('sha256',__FILE__);
    }
    private static function clientIp(): string
    {
        $ip=(string)($_SERVER['REMOTE_ADDR']??'0.0.0.0');
        $trusted=Bootstrap::getConfig('security.trusted_proxies',[]);
        if (is_array($trusted) && in_array($ip,$trusted,true)) {
            $candidate=trim(explode(',',(string)($_SERVER['HTTP_X_FORWARDED_FOR']??''))[0]);
            if (filter_var($candidate,FILTER_VALIDATE_IP)) $ip=$candidate;
        }
        return filter_var($ip,FILTER_VALIDATE_IP)?$ip:'0.0.0.0';
    }
    private static function fingerprint(int $campaignId,string $type,?int $userId,string $ip,string $ua): string
    {
        $bucket=(int)floor(time()/86400);
        return hash_hmac('sha256',implode('|',[$campaignId,$type,$ip,hash('sha256',$ua),$bucket]),self::secret());
    }
    private static function looksLikeBot(string $ua): bool
    {
        if ($ua==='') return true;
        return (bool)preg_match('/bot|crawler|spider|slurp|headless|curl|wget|python-requests|scrapy|facebookexternalhit|preview/i',$ua);
    }
}
