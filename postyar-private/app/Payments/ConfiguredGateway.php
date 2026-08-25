<?php
namespace WHCM\Payments;

use WHCM\Core\Bootstrap;

/**
 * Safe placeholder adapter for named gateways. It deliberately refuses to initiate or verify
 * real money transactions until the provider-specific adapter has been configured and reviewed.
 */
final class ConfiguredGateway implements PaymentGatewayInterface
{
    public function __construct(private string $providerId) {}

    public function createPayment(int $orderId, int $userId, float $amount, string $returnUrl): array
    {
        $cfg = $this->config();
        if (($cfg['enabled'] ?? '0') !== '1') {
            throw new \RuntimeException('این درگاه هنوز فعال نشده است. ابتدا تنظیمات و احراز پذیرنده را کامل کنید.');
        }
        if (($cfg['verified'] ?? '0') !== '1') {
            throw new \RuntimeException('درگاه تا زمان تأیید تنظیمات توسط مدیر در حالت fail-closed است.');
        }
        throw new \RuntimeException('Adapter اختصاصی این درگاه هنوز به قرارداد رسمی API متصل نشده است. هیچ تراکنش مالی ایجاد نشد.');
    }

    public function verifyCallback(array $payload): array
    {
        $cfg = $this->config();
        if (($cfg['enabled'] ?? '0') !== '1' || ($cfg['verified'] ?? '0') !== '1') {
            return ['success'=>false,'provider'=>$this->providerId,'error'=>'درگاه فعال/تأیید نشده است.'];
        }
        return ['success'=>false,'provider'=>$this->providerId,'error'=>'Provider adapter هنوز پیاده‌سازی رسمی نشده است.'];
    }

    private function config(): array
    {
        $db = Bootstrap::getDB();
        $prefix = 'payment_gateway_'.$this->providerId.'_';
        $stmt = $db->prepare("SELECT key_name,key_value FROM settings WHERE tenant_id=0 AND key_name LIKE ?");
        $stmt->execute([$prefix.'%']);
        $out=[];
        foreach ($stmt->fetchAll() as $row) $out[substr($row['key_name'], strlen($prefix))] = $row['key_value'];
        return $out;
    }
}
