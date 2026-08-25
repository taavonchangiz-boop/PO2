<?php
namespace WHCM\Payments;

/**
 * Provider catalog and configuration boundary for subscription + advertising payments.
 * Named providers are intentionally fail-closed until their merchant credentials/API contract are configured.
 */
final class PaymentProviderRegistry
{
    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        return [
            'zarinpal' => ['name'=>'زرین‌پال','kind'=>'intermediary','fields'=>['merchant_id','sandbox','callback_url']],
            'zibal' => ['name'=>'زیبال','kind'=>'intermediary','fields'=>['merchant','sandbox','callback_url','request_url','start_url','verify_url']],
            'idpay' => ['name'=>'آیدی‌پی','kind'=>'intermediary','fields'=>['api_key','sandbox','callback_url']],
            'nextpay' => ['name'=>'نکست‌پی','kind'=>'intermediary','fields'=>['api_key','callback_url']],
            'payir' => ['name'=>'پی‌آی','kind'=>'intermediary','fields'=>['api_key','callback_url']],
            'aqayepardakht' => ['name'=>'آقای پرداخت','kind'=>'intermediary','fields'=>['pin','callback_url']],
            'mellat' => ['name'=>'به‌پرداخت ملت','kind'=>'direct','fields'=>['terminal_id','username','password','callback_url','wsdl_url']],
            'saman' => ['name'=>'سامان','kind'=>'direct','fields'=>['merchant_id','callback_url','gateway_url']],
            'pasargad' => ['name'=>'پاسارگاد','kind'=>'direct','fields'=>['terminal_id','merchant_code','certificate_path','callback_url']],
            'tejarat' => ['name'=>'تجارت','kind'=>'direct','fields'=>['terminal_id','username','password','callback_url','service_url']],
            'saderat' => ['name'=>'صادرات','kind'=>'direct','fields'=>['terminal_id','username','password','callback_url','service_url']],
            'custom' => ['name'=>'درگاه سفارشی','kind'=>'custom','fields'=>['request_url','verify_url','callback_url','http_method','secret']],
        ];
    }

    public static function get(string $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    public static function activeId(): string
    {
        try {
            $db = \WHCM\Core\Bootstrap::getDB();
            $stmt = $db->query("SELECT key_value FROM settings WHERE tenant_id=0 AND key_name='payment_gateway_active' LIMIT 1");
            $value = strtolower(trim((string)($stmt->fetchColumn() ?: 'manual')));
            return $value === 'manual' || isset(self::all()[$value]) ? $value : 'manual';
        } catch (\Throwable $e) {
            return 'manual';
        }
    }

    public static function isEnabledAndVerified(string $id): bool
    {
        if (!isset(self::all()[$id])) return false;
        try {
            $db = \WHCM\Core\Bootstrap::getDB();
            $prefix = 'payment_gateway_' . $id . '_';
            $stmt = $db->prepare("SELECT key_name,key_value FROM settings WHERE tenant_id=0 AND key_name IN (?,?)");
            $stmt->execute([$prefix.'enabled', $prefix.'verified']);
            $cfg=[];
            foreach ($stmt->fetchAll() as $row) $cfg[$row['key_name']]=$row['key_value'];
            return ($cfg[$prefix.'enabled'] ?? '0') === '1' && ($cfg[$prefix.'verified'] ?? '0') === '1';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Fail-closed adapter: no money flow is enabled without an explicit provider implementation. */
    public static function adapter(string $id): PaymentGatewayInterface
    {
        if (!isset(self::all()[$id])) {
            throw new \InvalidArgumentException('درگاه پرداخت انتخاب‌شده معتبر نیست.');
        }
        return new ConfiguredGateway($id);
    }
}
