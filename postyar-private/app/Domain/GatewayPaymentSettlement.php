<?php
namespace WHCM\Domain;

use WHCM\Core\Bootstrap;
use WHCM\Core\Transaction;

/**
 * Exactly-once settlement boundary for already provider-verified callbacks.
 * Provider adapters must perform remote verification before this service is called.
 */
final class GatewayPaymentSettlement
{
    public static function settle(array $verification): array
    {
        $orderId=(int)($verification['order_id'] ?? 0);
        $provider=PaymentOrder::normalizeProvider((string)($verification['provider'] ?? ''));
        $reference=PaymentOrder::normalizeReference((string)($verification['provider_reference'] ?? ''));
        $eventKey=PaymentOrder::normalizeReference((string)($verification['event_key'] ?? ''));
        $amount=round((float)($verification['amount'] ?? -1),2);
        $verified=(bool)($verification['verified'] ?? false);
        if($orderId<=0||$reference===null||$eventKey===null||$amount<0||!$verified) throw new \RuntimeException('تأیید پرداخت ناقص یا نامعتبر است.');

        $payload=$verification['payload'] ?? [];
        $payloadHash=hash('sha256',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

        return Transaction::run(function(\PDO $db) use($orderId,$provider,$reference,$eventKey,$amount,$payloadHash,$verification): array {
            $driver=Bootstrap::getConfig('database.driver','sqlite');
            $sql='SELECT * FROM payment_orders WHERE id=? LIMIT 1'; if($driver==='mysql') $sql.=' FOR UPDATE';
            $stmt=$db->prepare($sql);$stmt->execute([$orderId]);$order=$stmt->fetch();
            if(!$order) throw new \RuntimeException('سفارش پرداخت یافت نشد.');
            if((string)$order['provider']!==$provider) throw new \RuntimeException('درگاه callback با درگاه سفارش مطابقت ندارد.');
            if((string)$order['order_type']!=='subscription') throw new \RuntimeException('نوع سفارش برای این settlement پشتیبانی نمی‌شود.');

            $eventStmt=$db->prepare('SELECT * FROM payment_events WHERE provider=? AND event_key=? LIMIT 1');$eventStmt->execute([$provider,$eventKey]);$event=$eventStmt->fetch();
            if($event){
                if((string)$event['outcome']==='paid') return self::resultFromOrder($db,$orderId);
                return ['accepted'=>false,'payment_order_id'=>$orderId,'public_id'=>(string)$order['public_id'],'reason'=>(string)($event['error_code'] ?? 'previously_rejected')];
            }

            if((string)$order['status']===PaymentOrder::PAID) {
                self::recordEvent($db,$orderId,$provider,$eventKey,$reference,$amount,$payloadHash,'paid',null);
                return self::resultFromOrder($db,$orderId);
            }
            if(in_array((string)$order['status'],[PaymentOrder::FAILED,PaymentOrder::CANCELLED,PaymentOrder::EXPIRED],true)) throw new \RuntimeException('سفارش پرداخت دیگر قابل تسویه نیست.');
            if(!empty($order['expires_at']) && strtotime((string)$order['expires_at'])<=time()) {
                $stmt=$db->prepare("UPDATE payment_orders SET status='expired',updated_at=CURRENT_TIMESTAMP WHERE id=? AND status<>'paid'");$stmt->execute([$orderId]);
                self::recordEvent($db,$orderId,$provider,$eventKey,$reference,$amount,$payloadHash,'rejected','expired');
                return ['accepted'=>false,'payment_order_id'=>$orderId,'public_id'=>(string)$order['public_id'],'reason'=>'expired'];
            }

            $expected=round((float)$order['amount'],2);
            if(abs($expected-$amount)>0.01){
                self::recordEvent($db,$orderId,$provider,$eventKey,$reference,$amount,$payloadHash,'rejected','amount_mismatch');
                return ['accepted'=>false,'payment_order_id'=>$orderId,'public_id'=>(string)$order['public_id'],'reason'=>'amount_mismatch'];
            }

            $stmt=$db->prepare('SELECT id FROM payment_orders WHERE provider=? AND provider_reference=? AND id<>? LIMIT 1');$stmt->execute([$provider,$reference,$orderId]);
            if($stmt->fetch()) { self::recordEvent($db,$orderId,$provider,$eventKey,$reference,$amount,$payloadHash,'rejected','duplicate_provider_reference'); return ['accepted'=>false,'payment_order_id'=>$orderId,'public_id'=>(string)$order['public_id'],'reason'=>'duplicate_provider_reference']; }

            $stmt=$db->prepare("UPDATE payment_orders SET status='callback_received',provider_reference=?,provider_payload_hash=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ('pending','redirected','callback_received')");
            $stmt->execute([$reference,$payloadHash,$orderId]); if($stmt->rowCount()!==1) throw new \RuntimeException('تغییر وضعیت سفارش همزمان شکست خورد.');

            $order=self::findLocked($db,$orderId);
            $planId=(int)$order['plan_id'];$userId=(int)$order['user_id'];
            $stmt=$db->prepare('SELECT * FROM plans WHERE id=? LIMIT 1');$stmt->execute([$planId]);$plan=$stmt->fetch();if(!$plan) throw new \RuntimeException('پلن سفارش یافت نشد.');

            // Never use callback/client pricing. The immutable order quote is authoritative.
            $now=date('Y-m-d H:i:s');
            $stmt=$db->prepare("UPDATE subscriptions SET status='expired' WHERE user_id=? AND status='active'");$stmt->execute([$userId]);
            $duration=(int)$plan['duration_days'];$end=$duration>0?date('Y-m-d H:i:s',strtotime("+{$duration} days")):'2099-12-30 00:00:00';
            $stmt=$db->prepare("INSERT INTO subscriptions(user_id,plan_id,start_date,end_date,status) VALUES(?,?,?,?, 'active')");$stmt->execute([$userId,$planId,$now,$end]);$subscriptionId=(int)$db->lastInsertId();

            // Referral reward is atomic with settlement; failure rolls the payment back.
            Referral::processFirstPurchase($userId,$planId,$amount);

            $stmt=$db->prepare("UPDATE payment_orders SET status='paid',paid_at=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='callback_received'");$stmt->execute([$now,$orderId]);if($stmt->rowCount()!==1) throw new \RuntimeException('ثبت نهایی settlement شکست خورد.');
            self::recordEvent($db,$orderId,$provider,$eventKey,$reference,$amount,$payloadHash,'paid',null);
            return ['accepted'=>true,'payment_order_id'=>$orderId,'public_id'=>(string)$order['public_id'],'user_id'=>$userId,'plan_id'=>$planId,'subscription_id'=>$subscriptionId,'amount'=>$amount,'provider'=>$provider,'provider_reference'=>$reference];
        });
    }

    private static function resultFromOrder(\PDO $db,int $orderId): array
    {
        $order=self::findLocked($db,$orderId); return ['payment_order_id'=>$orderId,'public_id'=>(string)$order['public_id'],'user_id'=>(int)$order['user_id'],'plan_id'=>(int)$order['plan_id'],'amount'=>round((float)$order['amount'],2),'provider'=>(string)$order['provider'],'provider_reference'=>(string)($order['provider_reference']??''),'already_settled'=>true,'accepted'=>true];
    }
    private static function findLocked(\PDO $db,int $id): array
    {
        $sql='SELECT * FROM payment_orders WHERE id=? LIMIT 1';if(Bootstrap::getConfig('database.driver','sqlite')==='mysql')$sql.=' FOR UPDATE';$stmt=$db->prepare($sql);$stmt->execute([$id]);$row=$stmt->fetch();if(!$row)throw new \RuntimeException('سفارش پرداخت یافت نشد.');return $row;
    }
    private static function recordEvent(\PDO $db,int $orderId,string $provider,string $eventKey,string $reference,float $amount,string $hash,string $outcome,?string $error): void
    {
        try{$stmt=$db->prepare('INSERT INTO payment_events(payment_order_id,provider,event_type,event_key,provider_reference,amount,payload_hash,outcome,error_code) VALUES(?,?,?,?,?,?,?,?,?)');$stmt->execute([$orderId,$provider,'callback',$eventKey,$reference,$amount,$hash,$outcome,$error]);}catch(\PDOException $e){if(str_contains(strtolower($e->getMessage()),'unique')) return;throw $e;}
    }
}
