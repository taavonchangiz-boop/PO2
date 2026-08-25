<?php
/**
 * پُست‌یار — cPanel production/staging configuration.
 * Keep this file outside public_html.
 */
return [
    'app' => [
        'name' => 'پُست‌یار',
        'url' => '',
        'locale' => 'fa',
        'timezone' => 'Asia/Tehran',
        'env' => 'production',
    ],
    'paths' => [
        'public_assets_path' => getenv('POSTYAR_PUBLIC_ASSETS_PATH') ?: dirname(__DIR__, 2) . '/public_html/assets',
        'private_storage_path' => getenv('POSTYAR_PRIVATE_STORAGE_PATH') ?: dirname(__DIR__) . '/storage/private',
        'public_assets_url' => getenv('POSTYAR_PUBLIC_ASSETS_URL') ?: '/assets',
    ],
    'database' => [
        'driver' => 'mysql',
        'sqlite' => [
            'path' => __DIR__ . '/../storage/db/whcm_saas.sqlite',
        ],
        'mysql' => [
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => '',
            'username' => '',
            'password' => '',
            'charset' => 'utf8mb4',
        ],
    ],
    'cache' => [
        'prefix' => 'postyar',
        'redis' => [
            'enabled' => false,
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
            'password' => '',
            'timeout' => 0.5,
        ],
    ],
    'observability' => [
        'metrics_token' => '',
    ],
    'security' => [
        'salt' => '',
        'secret_key' => '', // Prefer environment variable POSTYAR_SECRET_KEY in production.
        'session_lifetime' => 86400,
        'trusted_proxies' => [],
        'admin_ip_whitelist' => [],
    ],
    'upload' => [
        'max_size_mb' => 5,
        'allowed_types' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
    ],
    'defaults' => [
        'gold_api_url' => 'https://api.tgju.org/v1/data/sana/home',
        'gold_currency' => 'toman',
    ],
    'mail' => [
        'enabled' => false,
        'host' => '',
        'port' => 587,
        'username' => '',
        'password' => '',
        'encryption' => 'tls',
        'auth' => '1',
        'timeout' => 15,
        'from_address' => '',
        'from_name' => 'پُست‌یار',
        'reply_to' => '',
        'reply_name' => '',
    ],
    'sms' => [
        'enabled' => false,
        'provider' => 'smsir',
        'api_key' => '',
        'line_number' => '',
    ],
    'vapid' => [
        'enabled' => false,
        'subject' => '',
        'public_key' => '',
        'private_key_pem' => '',
    ],
];
