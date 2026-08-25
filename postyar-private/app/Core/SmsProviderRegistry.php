<?php
namespace WHCM\Core;

/** Provider catalog. Endpoint/credentials remain administrator-controlled and secrets never leave server-side code. */
final class SmsProviderRegistry
{
    public static function all(): array
    {
        return [
            'smsir'=>['name'=>'اس‌ام‌اس‌آی‌آر','fields'=>['api_key','line_number','base_url']],
            'kavenegar'=>['name'=>'کاوه‌نگار','fields'=>['api_key','sender','base_url']],
            'melipayamak'=>['name'=>'ملی پیامک','fields'=>['username','password','sender','base_url']],
            'farazsms'=>['name'=>'فراز پیامک','fields'=>['api_key','sender','base_url']],
            'custom'=>['name'=>'ارائه‌دهنده سفارشی','fields'=>['base_url','api_key','sender']],
        ];
    }

    public static function get(string $id): ?array { return self::all()[$id] ?? null; }

    public static function provider(string $id): SmsProviderInterface
    {
        if (!isset(self::all()[$id])) throw new \InvalidArgumentException('ارائه‌دهنده پیامک نامعتبر است.');
        return new ConfiguredSmsProvider($id);
    }
}
