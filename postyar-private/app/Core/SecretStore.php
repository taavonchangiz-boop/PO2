<?php
namespace WHCM\Core;

/** Server-side encryption for integration credentials stored in the settings table. */
final class SecretStore
{
    private const PREFIX = 'enc:v1:';

    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') return '';
        $key = self::key();
        if ($key === null) throw new \RuntimeException('POSTYAR_SECRET_KEY تنظیم نشده است؛ ذخیره credential متوقف شد.');
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false) throw new \RuntimeException('رمزنگاری credential ناموفق بود.');
        return self::PREFIX.base64_encode($iv.$tag.$cipher);
    }

    public static function decrypt(string $value): string
    {
        if ($value === '' || !str_starts_with($value, self::PREFIX)) return $value;
        $key=self::key(); if ($key===null) return '';
        $raw=base64_decode(substr($value,strlen(self::PREFIX)),true);
        if ($raw===false || strlen($raw)<28) return '';
        $iv=substr($raw,0,12); $tag=substr($raw,12,16); $cipher=substr($raw,28);
        $plain=openssl_decrypt($cipher,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'');
        return $plain===false?'':$plain;
    }

    private static function key(): ?string
    {
        $raw=(string)(getenv('POSTYAR_SECRET_KEY') ?: Bootstrap::getConfig('security.secret_key',''));
        if ($raw==='') return null;
        return hash('sha256',$raw,true);
    }
}
