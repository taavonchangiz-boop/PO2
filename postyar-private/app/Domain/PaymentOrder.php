<?php
namespace WHCM\Domain;

use WHCM\Core\Bootstrap;
use WHCM\Core\Transaction;

/**
 * Provider-neutral payment order ledger.
 * The browser never controls amount, owner, plan or settlement state.
 */
final class PaymentOrder
{
    public const PENDING = 'pending';
    public const REDIRECTED = 'redirected';
    public const CALLBACK_RECEIVED = 'callback_received';
    public const PAID = 'paid';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';
    public const EXPIRED = 'expired';

    public static function createSubscription(int $userId, int $planId, string $provider, string $idempotencyKey, int $ttlMinutes = 30): array
    {
        if ($userId <= 0 || $planId <= 0) throw new \InvalidArgumentException('شناسه کاربر یا پلن نامعتبر است.');
        $provider = self::normalizeProvider($provider);
        $idempotencyKey = Idempotency::normalizeKey($idempotencyKey);
        if ($idempotencyKey === null) throw new \InvalidArgumentException('کلید idempotency نامعتبر است.');
        $ttlMinutes = max(5, min(1440, $ttlMinutes));

        $quote = PaymentPricing::quote($userId, $planId);
        $amount = round((float)$quote['amount'], 2);
        if ($amount <= 0) throw new \RuntimeException('مبلغ پرداخت معتبر نیست.');

        return Transaction::run(function (\PDO $db) use ($userId,$planId,$provider,$idempotencyKey,$ttlMinutes,$quote,$amount): array {
            $stmt = $db->prepare('SELECT * FROM payment_orders WHERE user_id=? AND idempotency_key=? LIMIT 1');
            $stmt->execute([$userId,$idempotencyKey]);
            $existing = $stmt->fetch();
            if ($existing) return $existing;

            $publicId = bin2hex(random_bytes(16));
            $quoteJson = json_encode([
                'plan_id'=>$planId,
                'amount'=>$amount,
                'currency'=>'IRR',
                'general_discount_percent'=>(int)$quote['general_discount_percent'],
                'early_renewal_eligible'=>(bool)$quote['early_renewal_eligible'],
                'early_renewal_discount_percent'=>(int)$quote['early_renewal_discount_percent'],
            ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $expiresAt = date('Y-m-d H:i:s', time()+($ttlMinutes*60));
            $stmt = $db->prepare('INSERT INTO payment_orders(public_id,user_id,order_type,plan_id,amount,currency,provider,status,idempotency_key,quote_json,expires_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)');
            $stmt->execute([$publicId,$userId,'subscription',$planId,$amount,'IRR',$provider,self::PENDING,$idempotencyKey,$quoteJson,$expiresAt]);
            return self::findForUpdate($db,(int)$db->lastInsertId());
        });
    }

    public static function find(int $id): ?array
    {
        $db=Bootstrap::getDB(); $stmt=$db->prepare('SELECT * FROM payment_orders WHERE id=? LIMIT 1'); $stmt->execute([$id]); return $stmt->fetch() ?: null;
    }

    public static function findByPublicId(string $publicId): ?array
    {
        $publicId=trim($publicId); if($publicId==='') return null;
        $db=Bootstrap::getDB(); $stmt=$db->prepare('SELECT * FROM payment_orders WHERE public_id=? LIMIT 1'); $stmt->execute([$publicId]); return $stmt->fetch() ?: null;
    }

    public static function markRedirected(int $id, string $providerReference): bool
    {
        $providerReference=self::normalizeReference($providerReference);
        if($id<=0||$providerReference===null) return false;
        $db=Bootstrap::getDB();
        $stmt=$db->prepare("UPDATE payment_orders SET status='redirected',provider_reference=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='pending' AND (expires_at IS NULL OR expires_at>CURRENT_TIMESTAMP)");
        $stmt->execute([$providerReference,$id]); return $stmt->rowCount()===1;
    }

    public static function expire(int $id): bool
    {
        $db=Bootstrap::getDB();
        $stmt=$db->prepare("UPDATE payment_orders SET status='expired',updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ('pending','redirected','callback_received') AND expires_at IS NOT NULL AND expires_at<=CURRENT_TIMESTAMP");
        $stmt->execute([$id]); return $stmt->rowCount()===1;
    }

    public static function normalizeProvider(string $provider): string
    {
        $provider=strtolower(trim($provider));
        if($provider==='' || !preg_match('/^[a-z0-9][a-z0-9_-]{1,79}$/',$provider)) throw new \InvalidArgumentException('درگاه پرداخت نامعتبر است.');
        return $provider;
    }

    public static function normalizeReference(string $reference): ?string
    {
        $reference=trim($reference); if($reference==='' || strlen($reference)>190) return null; return $reference;
    }

    private static function findForUpdate(\PDO $db,int $id): array
    {
        $sql='SELECT * FROM payment_orders WHERE id=? LIMIT 1';
        if(Bootstrap::getConfig('database.driver','sqlite')==='mysql') $sql.=' FOR UPDATE';
        $stmt=$db->prepare($sql); $stmt->execute([$id]); $row=$stmt->fetch();
        if(!$row) throw new \RuntimeException('سفارش پرداخت ایجاد نشد.'); return $row;
    }
}
