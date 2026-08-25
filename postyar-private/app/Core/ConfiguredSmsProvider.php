<?php
namespace WHCM\Core;

/**
 * Provider-neutral SMS adapter. It is deliberately fail-closed: a provider cannot be called
 * merely because an admin selected its name; credentials, endpoint and explicit verification are required.
 */
final class ConfiguredSmsProvider implements SmsProviderInterface
{
    public function __construct(private string $providerId) {}

    public function sendPattern(string $phone, string $template, array $parameters = []): array
    {
        $cfg=$this->config();
        if (($cfg['enabled']??'0')!=='1' || ($cfg['verified']??'0')!=='1') return ['success'=>false,'error'=>'ارائه‌دهنده پیامک فعال/تأیید نشده است.'];
        if (empty($cfg['base_url'])) return ['success'=>false,'error'=>'آدرس API ارائه‌دهنده پیامک تنظیم نشده است.'];
        // No provider-specific wire format is guessed. This prevents accidental production sends.
        return ['success'=>false,'error'=>'Adapter رسمی این ارائه‌دهنده هنوز به قرارداد API متصل نشده است.'];
    }

    public function test(string $phone): array
    {
        return $this->sendPattern($phone, 'test', []);
    }

    private function config(): array
    {
        $db=Bootstrap::getDB(); $prefix='sms_provider_'.$this->providerId.'_';
        $stmt=$db->prepare("SELECT key_name,key_value FROM settings WHERE tenant_id=0 AND key_name LIKE ?");
        $stmt->execute([$prefix.'%']); $out=[];
        foreach($stmt->fetchAll() as $row) $out[substr($row['key_name'],strlen($prefix))]=$row['key_value'];
        return $out;
    }
}
