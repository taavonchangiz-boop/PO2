<?php
namespace WHCM\Domain;

use WHCM\Core\Bootstrap;
use WHCM\Core\Transaction;

/**
 * Paid advertising sales workflow.
 *
 * Invariants:
 * - A submitted request is never publicly visible.
 * - The administrator is the only authority that sets the quoted amount.
 * - Card-to-card payment cannot activate an ad until explicitly verified.
 * - Future online gateways must settle through the same atomic paid transition.
 * - Campaign activation requires paid + approved state.
 */
final class AdSales
{
    public const ORDER_SUBMITTED = 'submitted';
    public const ORDER_AWAITING_PAYMENT = 'awaiting_payment';
    public const ORDER_PAYMENT_SUBMITTED = 'payment_submitted';
    public const ORDER_PAID = 'paid';
    public const ORDER_REJECTED = 'rejected';
    public const ORDER_CANCELLED = 'cancelled';
    public const ORDER_EXPIRED = 'expired';

    public const PAYMENT_UNPAID = 'unpaid';
    public const PAYMENT_PENDING = 'pending_verification';
    public const PAYMENT_PAID = 'paid';

    public static function placements(bool $activeOnly = true): array
    {
        $db = Bootstrap::getDB();
        $sql = 'SELECT id,code,title,description,unit_price_per_day,max_concurrent,is_active FROM ad_placements';
        if ($activeOnly) $sql .= ' WHERE is_active=1';
        $sql .= ' ORDER BY id ASC';
        return $db->query($sql)->fetchAll();
    }

    public static function createRequest(int $ownerId, array $input): int
    {
        if ($ownerId <= 0) throw new \InvalidArgumentException('کاربر نامعتبر است.');
        $start = self::normalizeDate((string)($input['starts_at'] ?? ''));
        $end = self::normalizeDate((string)($input['ends_at'] ?? ''));
        if (!$start || !$end || strtotime($end) <= strtotime($start)) throw new \InvalidArgumentException('بازه زمانی نامعتبر است.');

        $placementCodes = array_values(array_unique(array_filter(array_map('trim', (array)($input['placements'] ?? [])))));
        if (!$placementCodes) throw new \InvalidArgumentException('حداقل یک جایگاه تبلیغاتی الزامی است.');
        $creatives = (array)($input['creatives'] ?? []);
        if (!$creatives || count($creatives) > 10) throw new \InvalidArgumentException('تعداد اسلایدهای تبلیغاتی باید بین ۱ تا ۱۰ باشد.');

        $days = max(1, (int)ceil((strtotime($end) - strtotime($start)) / 86400));
        foreach ($creatives as $c) {
            $title=trim((string)($c['title'] ?? '')); $body=trim((string)($c['body_text'] ?? ''));
            $image=trim((string)($c['image_url'] ?? '')); $dest=trim((string)($c['destination_url'] ?? ''));
            if (mb_strlen($title)<2 || mb_strlen($title)>180 || ($body==='' && $image==='') || !Advertising::validateDestination($dest)) {
                throw new \InvalidArgumentException('یکی از اسلایدهای تبلیغاتی نامعتبر است.');
            }
        }
        $db = Bootstrap::getDB();
        return Transaction::run(function(\PDO $db) use ($ownerId,$start,$end,$placementCodes,$creatives,$days,$input): int {
            $stmt = $db->prepare('INSERT INTO ad_orders(owner_user_id,status,payment_status,requested_starts_at,requested_ends_at,currency,user_notes,created_at,updated_at) VALUES(?,?,?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)');
            $stmt->execute([$ownerId,self::ORDER_SUBMITTED,self::PAYMENT_UNPAID,$start,$end,'IRR',trim((string)($input['user_notes'] ?? ''))]);
            $orderId=(int)$db->lastInsertId();

            $placeholders=implode(',',array_fill(0,count($placementCodes),'?'));
            $stmt=$db->prepare("SELECT * FROM ad_placements WHERE is_active=1 AND code IN ($placeholders)");
            $stmt->execute($placementCodes); $placements=$stmt->fetchAll();
            if (count($placements)!==count($placementCodes)) throw new \InvalidArgumentException('یکی از جایگاه‌های انتخابی دیگر فعال نیست.');

            foreach ($placements as $p) {
                $unit=max(0,(float)$p['unit_price_per_day']);
                $line=round($unit*$days,2);
                $stmt=$db->prepare('INSERT INTO ad_order_items(order_id,placement_id,quantity,unit_price_per_day,days,line_amount) VALUES(?,?,?,?,?,?)');
                $stmt->execute([$orderId,(int)$p['id'],1,$unit,$days,$line]);
            }

            $first=$creatives[0];
            $image=trim((string)($first['image_url'] ?? ''));
            $title=trim((string)($first['title'] ?? ''));
            $dest=trim((string)($first['destination_url'] ?? ''));
            $stmt=$db->prepare("INSERT INTO ad_campaigns(owner_user_id,title,image_url,destination_url,status,starts_at,ends_at,created_at,updated_at,order_id,payment_status,placement_code) VALUES(?,?,?,?, 'pending',?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,?,'unpaid',?)");
            $stmt->execute([$ownerId,$title,$image,$dest,$start,$end,$orderId,$placementCodes[0]]);
            $campaignId=(int)$db->lastInsertId();

            foreach ($placements as $p) {
                $stmt=$db->prepare('INSERT INTO ad_campaign_placements(campaign_id,placement_id,placement_code) VALUES(?,?,?)');
                $stmt->execute([$campaignId,(int)$p['id'],(string)$p['code']]);
            }
            foreach ($creatives as $i=>$c) {
                $stmt=$db->prepare('INSERT INTO ad_creatives(campaign_id,title,body_text,image_url,destination_url,sort_order,is_active) VALUES(?,?,?,?,?,?,1)');
                $stmt->execute([$campaignId,trim((string)$c['title']),trim((string)($c['body_text']??'')),trim((string)($c['image_url']??'')),trim((string)$c['destination_url']),$i]);
            }
            return $orderId;
        });
    }

    public static function ownerOrders(int $ownerId, int $limit=50): array
    {
        $limit=max(1,min($limit,100)); $db=Bootstrap::getDB();
        $stmt=$db->prepare("SELECT o.*, c.id campaign_id,c.title campaign_title FROM ad_orders o LEFT JOIN ad_campaigns c ON c.order_id=o.id WHERE o.owner_user_id=? ORDER BY o.id DESC LIMIT {$limit}");
        $stmt->execute([$ownerId]); return $stmt->fetchAll();
    }

    public static function adminOrders(int $limit=200): array
    {
        $limit=max(1,min($limit,500)); $db=Bootstrap::getDB();
        $stmt=$db->query("SELECT o.*,u.name owner_name,u.email owner_email,c.id campaign_id,c.title campaign_title FROM ad_orders o JOIN users u ON u.id=o.owner_user_id LEFT JOIN ad_campaigns c ON c.order_id=o.id ORDER BY o.id DESC LIMIT {$limit}");
        return $stmt->fetchAll();
    }

    /** دریافت خلاقه‌های هر درخواست تبلیغاتی برای پیش‌نمایش مدیر، با یک Query برای جلوگیری از N+1. */
    public static function adminOrderCreatives(array $orderIds): array
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',$orderIds),static fn($id)=>$id>0)));
        if(!$ids) return [];
        $db=Bootstrap::getDB();
        $placeholders=implode(',',array_fill(0,count($ids),'?'));
        $stmt=$db->prepare("SELECT c.order_id,cr.id,cr.title,cr.body_text,cr.image_url,cr.destination_url,cr.sort_order
            FROM ad_campaigns c JOIN ad_creatives cr ON cr.campaign_id=c.id
            WHERE c.order_id IN ($placeholders) ORDER BY c.order_id ASC,cr.sort_order ASC,cr.id ASC");
        $stmt->execute($ids);
        $result=[];
        foreach($stmt->fetchAll() as $row){$oid=(int)$row['order_id'];$result[$oid][]=$row;}
        return $result;
    }

    public static function quote(int $orderId, float $amount, int $adminId, ?string $notes=null): bool
    {
        $amount=round($amount,2); if ($orderId<=0 || $amount<=0 || $adminId<=0) return false;
        return Transaction::run(function(\PDO $db) use ($orderId,$amount,$adminId,$notes): bool {
            $stmt=$db->prepare("UPDATE ad_orders SET quoted_amount=?,status=?,payment_status='unpaid',reviewed_by=?,reviewed_at=CURRENT_TIMESTAMP,admin_notes=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ('submitted','payment_submitted')");
            $stmt->execute([$amount,self::ORDER_AWAITING_PAYMENT,$adminId,$notes,$orderId]);
            if ($stmt->rowCount()===1) {
                $owner=(int)$db->query('SELECT owner_user_id FROM ad_orders WHERE id='.(int)$orderId)->fetchColumn();
                if ($owner>0) Notification::create($owner,'مبلغ تبلیغات تایید شد','مبلغ نهایی درخواست تبلیغات شما تعیین شد: '.number_format($amount,2).' ریال. اکنون می‌توانید پرداخت را ثبت کنید.','advertising','ads');
            }
            return $stmt->rowCount()===1;
        });
    }

    public static function submitCardPayment(int $orderId,int $ownerId,string $reference,string $receiptPhoto): bool
    {
        $reference=trim($reference); if ($orderId<=0||$ownerId<=0||$reference===''||strlen($reference)>120||$receiptPhoto==='') return false;
        $db=Bootstrap::getDB();
        $stmt=$db->prepare("UPDATE ad_orders SET payment_status=?,status=?,payment_method='card_to_card',payment_reference=?,receipt_photo=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND owner_user_id=? AND status='awaiting_payment' AND quoted_amount>0 AND payment_status='unpaid'");
        $stmt->execute([self::PAYMENT_PENDING,self::ORDER_PAYMENT_SUBMITTED,$reference,$receiptPhoto,$orderId,$ownerId]);
        return $stmt->rowCount()===1;
    }

    public static function approveCardPayment(int $orderId,int $adminId): bool
    {
        if ($orderId<=0||$adminId<=0) return false;
        return Transaction::run(function(\PDO $db) use($orderId,$adminId): bool {
            $driver=Bootstrap::getConfig('database.driver','sqlite');
            $sql="SELECT * FROM ad_orders WHERE id=? AND status='payment_submitted' AND payment_status='pending_verification' LIMIT 1";
            if($driver==='mysql') $sql.=' FOR UPDATE';
            $stmt=$db->prepare($sql);$stmt->execute([$orderId]);$order=$stmt->fetch();
            if(!$order) return false;
            $stmt=$db->prepare('SELECT c.id,c.starts_at,c.ends_at,cp.placement_id,cp.placement_code,p.max_concurrent FROM ad_campaigns c JOIN ad_campaign_placements cp ON cp.campaign_id=c.id JOIN ad_placements p ON p.id=cp.placement_id WHERE c.order_id=?');
            $stmt->execute([$orderId]); $campaignPlacements=$stmt->fetchAll();
            if(!$campaignPlacements) return false;
            $stmt=$db->prepare('SELECT requested_starts_at,requested_ends_at FROM ad_orders WHERE id=?');
            $stmt->execute([$orderId]); $window=$stmt->fetch(); if(!$window)return false;
            foreach($campaignPlacements as $cp) {
                $stmt=$db->prepare("SELECT COUNT(*) FROM ad_campaigns c JOIN ad_campaign_placements cp ON cp.campaign_id=c.id WHERE cp.placement_id=? AND c.status='approved' AND c.payment_status='paid' AND c.starts_at < ? AND c.ends_at > ? AND c.id <> ?");
                $stmt->execute([(int)$cp['placement_id'],$window['requested_ends_at'],$window['requested_starts_at'],(int)$cp['id']]);
                if((int)$stmt->fetchColumn() >= (int)$cp['max_concurrent']) return false;
            }
            $stmt=$db->prepare("UPDATE ad_orders SET status='paid',payment_status='paid',paid_at=CURRENT_TIMESTAMP,reviewed_by=?,reviewed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='payment_submitted' AND payment_status='pending_verification'");
            $stmt->execute([$adminId,$orderId]); if($stmt->rowCount()!==1)return false;
            $stmt=$db->prepare("UPDATE ad_campaigns SET status='approved',payment_status='paid',approved_at=CURRENT_TIMESTAMP,approved_by=?,activation_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE order_id=? AND status='pending'");
            $stmt->execute([$adminId,$orderId]);
            if ($stmt->rowCount()===1) {
                Notification::create((int)$order['owner_user_id'],'پرداخت تبلیغات تایید شد','پرداخت درخواست تبلیغاتی شما تایید شد و کمپین وارد وضعیت آماده نمایش شد.','advertising','ads');
            }
            return $stmt->rowCount()===1;
        });
    }

    public static function reject(int $orderId,int $adminId,?string $notes=null): bool
    {
        $db=Bootstrap::getDB(); $stmt=$db->prepare("UPDATE ad_orders SET status='rejected',reviewed_by=?,reviewed_at=CURRENT_TIMESTAMP,admin_notes=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status NOT IN ('paid','cancelled','expired')");
        $stmt->execute([$adminId,$notes,$orderId]); return $stmt->rowCount()===1;
    }

    public static function calculateSuggestedQuote(int $orderId): float
    {
        $db=Bootstrap::getDB();$stmt=$db->prepare('SELECT COALESCE(SUM(line_amount),0) FROM ad_order_items WHERE order_id=?');$stmt->execute([$orderId]);return round((float)$stmt->fetchColumn(),2);
    }

    public static function activeForPlacement(string $placementCode, int $limit = 8): array
    {
        $placementCode=trim($placementCode); if($placementCode==='') return [];
        $limit=max(1,min($limit,20)); $db=Bootstrap::getDB();
        $stmt=$db->prepare("SELECT a.id,a.title,COALESCE(c.image_url,a.image_url) image_url,COALESCE(c.destination_url,a.destination_url) destination_url,a.starts_at,a.ends_at,COALESCE(c.body_text,'') body_text,COALESCE(c.sort_order,0) sort_order
            FROM ad_campaigns a JOIN ad_campaign_placements cp ON cp.campaign_id=a.id LEFT JOIN ad_creatives c ON c.campaign_id=a.id AND c.is_active=1
            WHERE cp.placement_code=? AND a.status='approved' AND a.payment_status='paid' AND a.starts_at<=CURRENT_TIMESTAMP AND a.ends_at>CURRENT_TIMESTAMP
            ORDER BY a.id DESC,sort_order ASC LIMIT {$limit}");
        $stmt->execute([$placementCode]); return $stmt->fetchAll();
    }

    public static function statusLabel(string $status): string {
        return match ($status) {
            self::ORDER_SUBMITTED => 'در انتظار بررسی',
            self::ORDER_AWAITING_PAYMENT => 'در انتظار پرداخت',
            self::ORDER_PAYMENT_SUBMITTED => 'در انتظار تأیید پرداخت',
            self::ORDER_PAID => 'تکمیل و فعال',
            self::ORDER_REJECTED => 'رد شده',
            self::ORDER_CANCELLED => 'لغو شده',
            self::ORDER_EXPIRED => 'منقضی شده',
            default => 'نامشخص',
        };
    }

    public static function paymentStatusLabel(string $status): string {
        return match ($status) {
            self::PAYMENT_UNPAID => 'پرداخت نشده',
            self::PAYMENT_PENDING => 'در انتظار تأیید',
            self::PAYMENT_PAID => 'پرداخت تأیید شده',
            default => 'نامشخص',
        };
    }

    /** ایجاد مستقیم تبلیغ توسط مدیر؛ بدون عبور از فرایند پرداخت. */
    public static function createManualCampaign(int $ownerId, array $input): int {
        if ($ownerId <= 0) throw new \InvalidArgumentException('کاربر نامعتبر است.');
        $start=self::normalizeDate((string)($input['starts_at']??''));
        $end=self::normalizeDate((string)($input['ends_at']??''));
        if(!$start||!$end||strtotime($end)<=strtotime($start)) throw new \InvalidArgumentException('بازه زمانی نامعتبر است.');
        $placements=['global_top'];
        if(!$placements) throw new \InvalidArgumentException('حداقل یک جایگاه الزامی است.');
        $title=trim((string)($input['title']??'')); $image=trim((string)($input['image_url']??'')); $dest=trim((string)($input['destination_url']??''));
        if(mb_strlen($title)<2||$image===''||!Advertising::validateDestination($dest)) throw new \InvalidArgumentException('اطلاعات تبلیغ نامعتبر است.');
        return Transaction::run(function(\PDO $db) use($ownerId,$start,$end,$placements,$title,$image,$dest){
            $ph=implode(',',array_fill(0,count($placements),'?'));
            $st=$db->prepare("SELECT id,code FROM ad_placements WHERE is_active=1 AND code IN ($ph)"); $st->execute($placements); $rows=$st->fetchAll();
            if(count($rows)!==count($placements)) throw new \InvalidArgumentException('یکی از جایگاه‌ها نامعتبر یا غیرفعال است.');
            $st=$db->prepare("INSERT INTO ad_campaigns(owner_user_id,title,image_url,destination_url,status,starts_at,ends_at,created_at,updated_at,payment_status,placement_code) VALUES(?,?,?,?, 'approved',?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,'paid',?)");
            $st->execute([$ownerId,$title,$image,$dest,$start,$end,$placements[0]]); $id=(int)$db->lastInsertId();
            foreach($rows as $row){$x=$db->prepare('INSERT INTO ad_campaign_placements(campaign_id,placement_id,placement_code) VALUES(?,?,?)');$x->execute([$id,(int)$row['id'],$row['code']]);}
            $x=$db->prepare('INSERT INTO ad_creatives(campaign_id,title,body_text,image_url,destination_url,sort_order,is_active) VALUES(?,?,?,?,?,0,1)');
            $x->execute([$id,$title,'',$image,$dest]);
            return $id;
        });
    }

    private static function normalizeDate(string $value): ?string {
        $value=trim($value);
        if($value==='') return null;
        $value=strtr($value,['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
        if(preg_match('/^(.+?)\s+([0-2]?\d):([0-5]\d)(?::([0-5]\d))?$/u',$value,$m)){
            $date=TextFormat::normalize_ad_date($m[1]);
            if(!$date) return null;
            $h=(int)$m[2]; if($h>23) return null;
            return $date.' '.str_pad((string)$h,2,'0',STR_PAD_LEFT).':'.$m[3].':'.($m[4]??'00');
        }
        $date=TextFormat::normalize_ad_date($value);
        return $date?$date.' 00:00:00':null;
    }
}
