<?php
namespace WHCM\Core;
use WHCM\Domain\AntiAbuse;

/**
 * کلاس راه‌اندازی سامانه (Bootstrap)
 *
 * @package WHCM.Core
 */
class Bootstrap {
    /** @var array */
    private static $config = [];

    /** @var \PDO|null */
    private static $db = null;

    /**
     * اجرای اولیه سیستم — فقط بارگذاری و اتصال دیتابیس
     */
    public static function run() {
        // ۱. مدیریت خطاها
        self::setupErrorReporting();

        // ۲. ریجستر کردن Autoloader سفارشی
        spl_autoload_register([self::class, 'autoload']);

        // ۳. بارگذاری پیکربندی
        self::$config = require __DIR__ . '/../../config/config.php';

        // Request correlation is initialized before DB/controllers so every log can be traced.
        RequestContext::start();

        // ۴. تنظیم منطقه زمانی
        date_default_timezone_set(self::$config['app']['timezone'] ?? 'Asia/Tehran');

        // ۵. شروع سشن امن
        Session::start();

        // ۶. ایجاد دایرکتوری‌های مورد نیاز
        self::ensureDirectories();

        // ۷. راه‌اندازی دیتابیس و اجرای مایگریشن‌ها (فقط اولین بار)
        self::initDatabase();

        // ۸. اعمال Security Headers
        self::sendSecurityHeaders();

        // Metrics are persisted through Redis/APCu when available, so the endpoint is useful across requests.
        register_shutdown_function(function(): void {
            try {
                $status = http_response_code();
                Metrics::observeRequest($status > 0 ? $status : 200, RequestContext::elapsedMs());
            } catch (\Throwable $e) { /* observability must never break the request */ }
        });
    }

    /**
     * تنظیمات گزارش خطا بر اساس محیط
     */
    private static function setupErrorReporting(): void {
        $env = self::$config['app']['env'] ?? 'production';
        if ($env === 'development') {
            ini_set('display_errors', 1);
            ini_set('display_startup_errors', 1);
            error_reporting(E_ALL);
        } else {
            ini_set('display_errors', 0);
            ini_set('display_startup_errors', 0);
            error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
            ini_set('log_errors', 1);
        }
    }

    /**
     * اعمال هدرهای امنیتی HTTP
     */
    public static function sendSecurityHeaders(): void {
        // جلوگیری از Clickjacking
        if (!headers_sent()) {
            header('X-Frame-Options: SAMEORIGIN');
            header('X-Content-Type-Options: nosniff');
            header('X-XSS-Protection: 1; mode=block');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

            // HSTS — فقط اگر HTTPS باشد
            $is_secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
            if ($is_secure) {
                header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
            }
        }
    }

    /**
     * دریافت تنظیمات سیستم
     */
    public static function getConfig(?string $key = null, $default = null) {
        if ($key === 'app.url') {
            $configured = self::$config['app']['url'] ?? 'http://localhost:8000';
            if (($configured === 'http://localhost:8000' || empty($configured)) && isset($_SERVER['HTTP_HOST'])) {
                $is_secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
                $scheme = $is_secure ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'];
                $script = $_SERVER['SCRIPT_NAME'] ?? '';
                $dir = dirname($script);
                if ($dir === '/' || $dir === '\\') {
                    $dir = '';
                }
                if (substr($dir, -7) === '/public') {
                    $dir = substr($dir, 0, -7);
                }
                return $scheme . '://' . $host . rtrim($dir, '/');
            }
        }

        if ($key === null) {
            return self::$config;
        }

        $parts = explode('.', $key);
        $current = self::$config;

        foreach ($parts as $part) {
            if (!is_array($current) || !isset($current[$part])) {
                return $default;
            }
            $current = $current[$part];
        }

        return $current;
    }

    /**
     * دریافت کانکشن دیتابیس PDO به صورت Singleton
     */
    public static function getDB(): \PDO {
        if (self::$db === null) {
            self::initDatabase();
        }
        return self::$db;
    }

    /**
     * مکانیزم بارگذاری خودکار کلاس‌ها (PSR-4)
     */
    private static function autoload(string $class) {
        // ۱. لود کلاس‌های WHCM
        $prefix = 'WHCM\\';
        $base_dir = __DIR__ . '/../';

        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) === 0) {
            $relative_class = substr($class, $len);
            $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
            if (file_exists($file)) {
                require $file;
                return;
            }
        }

        // ۲. لود PHPMailer
        $pm_prefix = 'PHPMailer\\PHPMailer\\';
        $pm_len = strlen($pm_prefix);
        if (strncmp($pm_prefix, $class, $pm_len) === 0) {
            $pm_base = __DIR__ . '/../../vendor/phpmailer/phpmailer/';
            $pm_relative = substr($class, $pm_len);
            $pm_file = $pm_base . str_replace('\\', '/', $pm_relative) . '.php';
            if (file_exists($pm_file)) {
                require $pm_file;
            }
        }
    }

    /**
     * بررسی و ساخت پوشه‌های مورد نیاز
     */
    private static function ensureDirectories(): void {
        $publicAssets = (string) self::getConfig('paths.public_assets_path', __DIR__ . '/../../public/assets');
        $dirs = [
            __DIR__ . '/../../storage',
            __DIR__ . '/../../storage/db',
            __DIR__ . '/../../storage/uploads',
            __DIR__ . '/../../storage/logs',
            rtrim($publicAssets, '/\\') . '/uploads',
            rtrim($publicAssets, '/\\') . '/plans',
            rtrim($publicAssets, '/\\') . '/receipts',
        ];

        foreach ($dirs as $dir) {
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    /**
     * راه‌اندازی دیتابیس
     */
    private static function initDatabase(): void {
        if (self::$db !== null) {
            return;
        }

        $driver = self::getConfig('database.driver', 'sqlite');

        try {
            if ($driver === 'sqlite') {
                $path = self::getConfig('database.sqlite.path');
                self::$db = new \PDO("sqlite:" . $path);
                self::$db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                self::$db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
                // Production concurrency hardening for SQLite. WAL allows readers
                // to proceed while a writer commits; busy_timeout prevents transient
                // SQLITE_BUSY errors from becoming user-visible failures.
                self::$db->exec("PRAGMA foreign_keys = ON;");
                self::$db->exec("PRAGMA busy_timeout = 5000;");
                try { self::$db->exec("PRAGMA journal_mode = WAL;"); } catch (\Throwable $e) {
                    // Read-only/unsupported filesystems may reject WAL. Do not make
                    // boot fail solely because an optimization is unavailable.
                }
                self::$db->exec("PRAGMA synchronous = NORMAL;");
            } else {
                $host = self::getConfig('database.mysql.host');
                $port = self::getConfig('database.mysql.port', '3306');
                $dbname = self::getConfig('database.mysql.database');
                $user = self::getConfig('database.mysql.username');
                $pass = self::getConfig('database.mysql.password');
                $charset = self::getConfig('database.mysql.charset', 'utf8mb4');

                self::$db = new \PDO("mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}", $user, $pass);
                self::$db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                self::$db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
                self::$db->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
            }

            // اجرای مایگریشن‌ها فقط در اولین اجرا
            self::checkAndRunMigrations();

            // اجرای مایگریشن‌های نسخه‌دار (ارتقای تدریجی)
            self::runVersionedMigrations();

        } catch (\PDOException $e) {
            // در production فقط لاگ کن، اطلاعات حساس را نمایش نده
            if ((self::$config['app']['env'] ?? 'production') === 'development') {
                die("خطا در اتصال به دیتابیس: " . $e->getMessage());
            } else {
                die("خطای سیستمی. لطفاً بعداً تلاش کنید.");
            }
        }
    }

    /**
     * ایجاد جدول‌ها در صورت خالی بودن دیتابیس (فقط اولین بار)
     */
    private static function checkAndRunMigrations(): void {
        $db = self::$db;
        $hasTable = false;

        if (self::getConfig('database.driver') === 'sqlite') {
            $stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
            $hasTable = (bool) $stmt->fetch();
        } else {
            $dbname = self::getConfig('database.mysql.database');
            $stmt = $db->prepare("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'users'");
            $stmt->execute([$dbname]);
            $hasTable = (bool) $stmt->fetch();
        }

        if (!$hasTable) {
            $driver = self::getConfig('database.driver', 'sqlite');
            $filename = ($driver === 'mysql') ? 'install_mysql.sql' : 'install.sql';
            $migration_file = __DIR__ . '/../../migrations/' . $filename;

            if (file_exists($migration_file)) {
                $sql = file_get_contents($migration_file);
                $queries = self::splitSqlQueries($sql);
                foreach ($queries as $query) {
                    $query = trim($query);
                    if (!empty($query)) {
                        $db->exec($query);
                    }
                }
            }

            // پس از نصب اولیه، نسخه فعلی مایگریشن ثبت شود
            self::setMigrationVersion('schema_initial');
        }
    }

    /**
     * مایگریشن‌های نسخه‌دار — هر نسخه فقط یک‌بار اجرا می‌شود
     */
    private static function runVersionedMigrations(): void {
        $migrations = [
            'v2_add_plan_columns' => function($db) {
                $cols = ['payment_url TEXT NULL', 'image_url TEXT NULL', 'description TEXT NULL',
                         'early_renewal_discount INTEGER DEFAULT 0', 'general_discount INTEGER DEFAULT 0',
                         'discount_badge_text VARCHAR(150) NULL', 'is_featured INTEGER DEFAULT 0'];
                foreach ($cols as $col) {
                    try { $db->exec("ALTER TABLE plans ADD COLUMN $col"); } catch (\Exception $e) {}
                }
            },
            'v2_add_user_columns' => function($db) {
                try { $db->exec("ALTER TABLE users ADD COLUMN business_name VARCHAR(150) NULL"); } catch (\Exception $e) {}
                try { $db->exec("ALTER TABLE users ADD COLUMN business_type VARCHAR(150) NULL"); } catch (\Exception $e) {}
            },
            'v2_add_ticket_columns' => function($db) {
                try { $db->exec("ALTER TABLE tickets ADD COLUMN attachment TEXT NULL"); } catch (\Exception $e) {}
                try { $db->exec("ALTER TABLE tickets ADD COLUMN assigned_to INTEGER NULL"); } catch (\Exception $e) {}
                try { $db->exec("ALTER TABLE tickets ADD COLUMN priority VARCHAR(20) DEFAULT 'normal'"); } catch (\Exception $e) {}
                try { $db->exec("ALTER TABLE tickets ADD COLUMN created_by_admin INTEGER DEFAULT 0"); } catch (\Exception $e) {}
            },
            'v2_create_tickets_table' => function($db) {
                $driver = self::getConfig('database.driver', 'sqlite');
                try {
                    if ($driver === 'mysql') {
                        $db->exec("CREATE TABLE IF NOT EXISTS tickets (
                            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
                            subject VARCHAR(255) NOT NULL, category VARCHAR(100) NOT NULL,
                            message TEXT NOT NULL, status VARCHAR(50) DEFAULT 'open',
                            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                    } else {
                        $db->exec("CREATE TABLE IF NOT EXISTS tickets (
                            id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
                            subject VARCHAR(255) NOT NULL, category VARCHAR(100) NOT NULL,
                            message TEXT NOT NULL, status VARCHAR(50) DEFAULT 'open',
                            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                        );");
                    }
                } catch (\Exception $e) {}
            },
            'v3_referral_wallet' => function($db) {
                $driver = self::getConfig('database.driver', 'sqlite');

                // اضافه کردن ستون‌های جدید به جدول users
                $user_cols = [
                    'phone VARCHAR(15) NULL',
                    'referral_code VARCHAR(20) NULL',
                    'referred_by INTEGER NULL',
                    'referral_points DECIMAL(15,2) DEFAULT 0',
                    'wallet_balance DECIMAL(15,2) DEFAULT 0',
                ];
                foreach ($user_cols as $col) {
                    try { $db->exec("ALTER TABLE users ADD COLUMN $col"); } catch (\Exception $e) {}
                }
                // ایندکس یکتا برای referral_code (جداسازی شده برای سازگاری با SQLite قدیمی‌تر)
                try { $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_users_referral_code ON users(referral_code) WHERE referral_code IS NOT NULL"); } catch (\Exception $e) {}

                // ایجاد جدول referrals
                try {
                    if ($driver === 'mysql') {
                        $db->exec("CREATE TABLE IF NOT EXISTS referrals (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            referrer_id INT NOT NULL,
                            referred_id INT NOT NULL UNIQUE,
                            referral_code VARCHAR(20) NOT NULL,
                            reward_type VARCHAR(20) DEFAULT 'points',
                            reward_value DECIMAL(10,2) DEFAULT 0,
                            status VARCHAR(20) DEFAULT 'pending',
                            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                            rewarded_at DATETIME NULL,
                            FOREIGN KEY (referrer_id) REFERENCES users(id),
                            FOREIGN KEY (referred_id) REFERENCES users(id)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                    } else {
                        $db->exec("CREATE TABLE IF NOT EXISTS referrals (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            referrer_id INTEGER NOT NULL,
                            referred_id INTEGER NOT NULL UNIQUE,
                            referral_code VARCHAR(20) NOT NULL,
                            reward_type VARCHAR(20) DEFAULT 'points',
                            reward_value DECIMAL(10,2) DEFAULT 0,
                            status VARCHAR(20) DEFAULT 'pending',
                            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                            rewarded_at DATETIME NULL,
                            FOREIGN KEY (referrer_id) REFERENCES users(id),
                            FOREIGN KEY (referred_id) REFERENCES users(id)
                        );");
                    }
                } catch (\Exception $e) {}

                // ایجاد جدول wallet_transactions
                try {
                    if ($driver === 'mysql') {
                        $db->exec("CREATE TABLE IF NOT EXISTS wallet_transactions (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            user_id INT NOT NULL,
                            type VARCHAR(30) NOT NULL,
                            amount DECIMAL(15,2) NOT NULL,
                            balance_after DECIMAL(15,2) NOT NULL,
                            description TEXT,
                            reference_type VARCHAR(50),
                            reference_id INT,
                            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                            FOREIGN KEY (user_id) REFERENCES users(id)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                    } else {
                        $db->exec("CREATE TABLE IF NOT EXISTS wallet_transactions (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            user_id INTEGER NOT NULL,
                            type VARCHAR(30) NOT NULL,
                            amount DECIMAL(15,2) NOT NULL,
                            balance_after DECIMAL(15,2) NOT NULL,
                            description TEXT,
                            reference_type VARCHAR(50),
                            reference_id INTEGER,
                            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                            FOREIGN KEY (user_id) REFERENCES users(id)
                        );");
                    }
                } catch (\Exception $e) {}

                // ایجاد جدول referral_settings
                try {
                    if ($driver === 'mysql') {
                        $db->exec("CREATE TABLE IF NOT EXISTS referral_settings (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            setting_key VARCHAR(50) NOT NULL UNIQUE,
                            setting_value TEXT NOT NULL
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                    } else {
                        $db->exec("CREATE TABLE IF NOT EXISTS referral_settings (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            setting_key VARCHAR(50) NOT NULL UNIQUE,
                            setting_value TEXT NOT NULL
                        );");
                    }
                } catch (\Exception $e) {}

                // درج تنظیمات پیش‌فرض سیستم زیرمجموعه‌گیری
                $defaults = [
                    ['enabled', '1'],
                    ['register_reward_type', 'points'],
                    ['register_reward_value', '100'],
                    ['first_purchase_reward_type', 'percent'],
                    ['first_purchase_reward_value', '10'],
                    ['max_referrals_per_user', '100'],
                    ['monthly_reward_cap', '500000'],
                ];
                foreach ($defaults as [$key, $value]) {
                    try {
                        $stmt = $db->prepare("INSERT OR IGNORE INTO referral_settings (setting_key, setting_value) VALUES (?, ?)");
                        $stmt->execute([$key, $value]);
                    } catch (\Exception $e) {
                        try {
                            $stmt = $db->prepare("INSERT IGNORE INTO referral_settings (setting_key, setting_value) VALUES (?, ?)");
                            $stmt->execute([$key, $value]);
                        } catch (\Exception $e2) {}
                    }
                }
            },
            'v4_sms_system' => function($db) {
                $driver = self::getConfig('database.driver', 'sqlite');

                // ایجاد جدول sms_templates
                try {
                    if ($driver === 'mysql') {
                        $db->exec("CREATE TABLE IF NOT EXISTS sms_templates (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            event_key VARCHAR(50) NOT NULL UNIQUE,
                            template_name VARCHAR(100) NOT NULL,
                            template_id INT NOT NULL,
                            parameters TEXT DEFAULT '[]',
                            is_active INT DEFAULT 1,
                            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                    } else {
                        $db->exec("CREATE TABLE IF NOT EXISTS sms_templates (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            event_key VARCHAR(50) NOT NULL UNIQUE,
                            template_name VARCHAR(100) NOT NULL,
                            template_id INTEGER NOT NULL,
                            parameters TEXT DEFAULT '[]',
                            is_active INTEGER DEFAULT 1,
                            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                        );");
                    }
                } catch (\Exception $e) {}

                // ایجاد جدول sms_log
                try {
                    if ($driver === 'mysql') {
                        $db->exec("CREATE TABLE IF NOT EXISTS sms_log (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            template_id INT,
                            phone VARCHAR(15) NOT NULL,
                            user_id INT NULL,
                            status VARCHAR(20) DEFAULT 'pending',
                            response_code VARCHAR(20),
                            error_message TEXT,
                            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                    } else {
                        $db->exec("CREATE TABLE IF NOT EXISTS sms_log (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            template_id INTEGER,
                            phone VARCHAR(15) NOT NULL,
                            user_id INTEGER NULL,
                            status VARCHAR(20) DEFAULT 'pending',
                            response_code VARCHAR(20),
                            error_message TEXT,
                            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                        );");
                    }
                } catch (\Exception $e) {}

                // درج قالب‌های پیش‌فرض
                $defaults = [
                    ['registration',        'ثبت‌نام کاربر جدید',          0, '[]',  1],
                    ['payment_confirm',     'تایید تراکنش پرداخت',         0, '[]',  1],
                    ['subscription_expiry', 'یادآوری انقضای اشتراک',       0, '[]',  1],
                    ['password_reset',      'بازنشانی رمز عبور',           0, '[]',  1],
                    ['bulk_notification',   'اطلاع‌رسانی عمومی',            0, '[]',  1],
                ];
                foreach ($defaults as $row) {
                    try {
                        $stmt = $db->prepare("INSERT OR IGNORE INTO sms_templates (event_key, template_name, template_id, parameters, is_active) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute($row);
                    } catch (\Exception $e) {
                        try {
                            $stmt = $db->prepare("INSERT IGNORE INTO sms_templates (event_key, template_name, template_id, parameters, is_active) VALUES (?, ?, ?, ?, ?)");
                            $stmt->execute($row);
                        } catch (\Exception $e2) {}
                    }
                }
            },
            'v5_email_templates' => function($db) {
                $driver = self::getConfig('database.driver', 'sqlite');

                // ایجاد جدول email_templates
                try {
                    if ($driver === 'mysql') {
                        $db->exec("CREATE TABLE IF NOT EXISTS email_templates (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            event_key VARCHAR(50) NOT NULL UNIQUE,
                            template_name VARCHAR(100) NOT NULL,
                            subject VARCHAR(255) NOT NULL,
                            body_html TEXT NOT NULL,
                            variables TEXT DEFAULT '[]',
                            is_active INT DEFAULT 1,
                            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                    } else {
                        $db->exec("CREATE TABLE IF NOT EXISTS email_templates (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            event_key VARCHAR(50) NOT NULL UNIQUE,
                            template_name VARCHAR(100) NOT NULL,
                            subject VARCHAR(255) NOT NULL,
                            body_html TEXT NOT NULL,
                            variables TEXT DEFAULT '[]',
                            is_active INTEGER DEFAULT 1,
                            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                        );");
                    }
                } catch (\Exception $e) {}

                // ایجاد جدول email_log
                try {
                    if ($driver === 'mysql') {
                        $db->exec("CREATE TABLE IF NOT EXISTS email_log (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            template_id INT NULL,
                            to_address VARCHAR(255) NOT NULL,
                            user_id INT NULL,
                            subject VARCHAR(255),
                            status VARCHAR(20) DEFAULT 'pending',
                            error_message TEXT,
                            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                    } else {
                        $db->exec("CREATE TABLE IF NOT EXISTS email_log (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            template_id INTEGER NULL,
                            to_address VARCHAR(255) NOT NULL,
                            user_id INTEGER NULL,
                            subject VARCHAR(255),
                            status VARCHAR(20) DEFAULT 'pending',
                            error_message TEXT,
                            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                        );");
                    }
                } catch (\Exception $e) {}

                // تابع کمکی برای ساخت هدر ایمیل
                $emailHeader = function($title, $preheader = '') {
                    return "<!DOCTYPE html><html dir='rtl' lang='fa'><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width, initial-scale=1.0'>" .
                        "<title>$title</title>" .
                        "<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->" .
                        "</head><body style='margin:0; padding:0; background:#f1f5f9;'>";
                };

                $emailFooter = function($app_name) {
                    return "<tr><td style='background:#f1f5f9; padding:20px 30px; text-align:center;'>" .
                        "<p style='margin:0; color:#94a3b8; font-size:12px; font-family:Tahoma,Arial,sans-serif;'>" .
                        "این ایمیل توسط <strong style='color:#4f46e5;'>" . htmlspecialchars($app_name) . "</strong> ارسال شده است.<br>" .
                        "اگر شما این درخواست را نداده‌اید، لطفاً این ایمیل را نادیده بگیرید.</p>" .
                        "</td></tr></table></body></html>";
                };

                $emailBodyOpen = function($preheader = '') {
                    return ($preheader ? "<div style='display:none; font-size:1px; color:#f1f5f9; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden;'>$preheader</div>" : '') .
                        "<table role='presentation' width='100%' cellpadding='0' cellspacing='0' style='background:#f1f5f9; font-family:Tahoma,Arial,sans-serif; padding:20px 0;'>" .
                        "<tr><td align='center' style='padding:20px 10px;'>" .
                        "<table role='presentation' width='600' cellpadding='0' cellspacing='0' style='max-width:600px; width:100%; background:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 4px 24px rgba(0,0,0,0.08);'>";
                };

                $emailBodyClose = function() {
                    return "</table></td></tr>";
                };

                $ctaButton = function($url, $text) {
                    return "<tr><td style='padding:10px 0 30px; text-align:center;'>" .
                        "<a href='" . htmlspecialchars($url) . "' target='_blank' style='display:inline-block; background:linear-gradient(135deg,#10b981 0%,#059669 100%); color:#ffffff!important; padding:14px 40px; border-radius:10px; text-decoration:none; font-weight:bold; font-size:15px; font-family:Tahoma,Arial,sans-serif; box-shadow:0 4px 14px rgba(16,185,129,0.35);'>" . htmlspecialchars($text) . "</a></td></tr>";
                };

                // درج ۷ قالب ایمیل پیش‌فرض با طراحی حرفه‌ای
                $defaults = [
                    [
                        'welcome',
                        'خوش‌آمدگویی ثبت‌نام',
                        'خوش آمدید به {{app_name}} {{name}} عزیز! 🎉',
                        $emailHeader('خوش‌آمدید') .
                        $emailBodyOpen('به پلتفرم مدیریت هوشمند کانال‌ها خوش آمدید') .
                        "<tr><td style='background:linear-gradient(135deg,#312e81 0%,#4f46e5 50%,#6366f1 100%); padding:40px 30px; text-align:center;'>" .
                        "<h1 style='margin:0; color:#ffffff; font-size:26px; font-family:Tahoma,Arial,sans-serif;'>🎉 خوش آمدید!</h1>" .
                        "<p style='margin:8px 0 0; color:rgba(255,255,255,0.85); font-size:14px; font-family:Tahoma,Arial,sans-serif;'>" . "{{app_name}}" . " — سامانه مستقل مدیریت هوشمند کانال‌ها</p>" .
                        "</td></tr>" .
                        "<tr><td style='padding:35px 30px 10px;'>" .
                        "<h2 style='margin:0 0 8px; color:#1e293b; font-size:20px; font-family:Tahoma,Arial,sans-serif;'>سلام {{name}} عزیز،</h2>" .
                        "<p style='margin:0; color:#475569; font-size:14px; line-height:2; font-family:Tahoma,Arial,sans-serif;'>ثبت‌نام شما با موفقیت انجام شد! از اینکه <strong style='color:#4f46e5;'>{{app_name}}</strong> را انتخاب کردید سپاسگزاریم.</p>" .
                        "</td></tr>" .
                        "<tr><td style='padding:15px 30px;'>" .
                        "<p style='margin:0 0 12px; color:#475569; font-size:14px; font-family:Tahoma,Arial,sans-serif;'>با <strong style='color:#1e293b;'>{{app_name}}</strong> می‌توانید از امکانات زیر بهره‌مند شوید:</p>" .
                        "<table role='presentation' width='100%' cellpadding='0' cellspacing='0' style='margin-top:8px;'>" .
                        "<tr><td style='padding:8px 0;'><span style='display:inline-block; width:28px; height:28px; background:linear-gradient(135deg,#4f46e5,#6366f1); color:white; text-align:center; line-height:28px; border-radius:8px; font-size:13px; margin-left:10px;'>📱</span><span style='color:#334155; font-size:13px; font-family:Tahoma,Arial,sans-serif;'>مدیریت چندگانه کانال‌های تلگرام و اینستاگرام</span></td></tr>" .
                        "<tr><td style='padding:8px 0;'><span style='display:inline-block; width:28px; height:28px; background:linear-gradient(135deg,#10b981,#059669); color:white; text-align:center; line-height:28px; border-radius:8px; font-size:13px; margin-left:10px;'>🤖</span><span style='color:#334155; font-size:13px; font-family:Tahoma,Arial,sans-serif;'>ارسال هوشمند پست با هوش مصنوعی</span></td></tr>" .
                        "<tr><td style='padding:8px 0;'><span style='display:inline-block; width:28px; height:28px; background:linear-gradient(135deg,#f59e0b,#d97706); color:white; text-align:center; line-height:28px; border-radius:8px; font-size:13px; margin-left:10px;'>📊</span><span style='color:#334155; font-size:13px; font-family:Tahoma,Arial,sans-serif;'>آمار دقیق کلیک و بازدید پست‌ها</span></td></tr>" .
                        "<tr><td style='padding:8px 0;'><span style='display:inline-block; width:28px; height:28px; background:linear-gradient(135deg,#ec4899,#db2777); color:white; text-align:center; line-height:28px; border-radius:8px; font-size:13px; margin-left:10px;'>🎫</span><span style='color:#334155; font-size:13px; font-family:Tahoma,Arial,sans-serif;'>پشتیبانی آنلاین و سیستم تیکت</span></td></tr>" .
                        "</table></td></tr>" .
                        "<tr><td style='padding:20px 30px 10px; text-align:center;'><p style='margin:0; color:#64748b; font-size:13px; font-family:Tahoma,Arial,sans-serif;'>برای شروع کار، روی دکمه زیر کلیک کنید:</p></td></tr>" .
                        $ctaButton('{{app_url}}', 'ورود به پنل کاربری') .
                        $emailBodyClose() .
                        $emailFooter('{{app_name}}'),
                        json_encode(['name', 'app_url', 'app_name'], JSON_UNESCAPED_UNICODE),
                        1,
                    ],
                    [
                        'payment_confirm',
                        'تاییدیه پرداخت',
                        'تاییدیه پرداخت اشتراک {{plan_name}} — {{app_name}}',
                        $emailHeader('تاییدیه پرداخت') .
                        $emailBodyOpen('پرداخت شما با موفقیت تایید شد') .
                        "<tr><td style='background:linear-gradient(135deg,#064e3b 0%,#047857 50%,#10b981 100%); padding:40px 30px; text-align:center;'>" .
                        "<h1 style='margin:0; color:#ffffff; font-size:26px; font-family:Tahoma,Arial,sans-serif;'>✅ تاییدیه پرداخت</h1>" .
                        "<p style='margin:8px 0 0; color:rgba(255,255,255,0.85); font-size:14px; font-family:Tahoma,Arial,sans-serif;'>تراکنش مالی شما با موفقیت انجام شد</p>" .
                        "</td></tr>" .
                        "<tr><td style='padding:35px 30px 10px;'>" .
                        "<h2 style='margin:0 0 8px; color:#1e293b; font-size:20px; font-family:Tahoma,Arial,sans-serif;'>سلام {{name}} عزیز،</h2>" .
                        "<p style='margin:0; color:#475569; font-size:14px; line-height:2; font-family:Tahoma,Arial,sans-serif;'>پرداخت شما با موفقیت تایید و ثبت شد. جزئیات تراکنش:</p>" .
                        "</td></tr>" .
                        "<tr><td style='padding:15px 30px;'>" .
                        "<table role='presentation' width='100%' cellpadding='0' cellspacing='0' style='background:#f8fafc; border-radius:12px; overflow:hidden; border:1px solid #e2e8f0;'>" .
                        "<tr><td style='padding:14px 20px; border-bottom:1px solid #e2e8f0;'><span style='color:#64748b; font-size:13px; font-family:Tahoma,Arial,sans-serif;'>پلن اشتراک:</span><br><strong style='color:#1e293b; font-size:15px; font-family:Tahoma,Arial,sans-serif;'>{{plan_name}}</strong></td></tr>" .
                        "<tr><td style='padding:14px 20px; border-bottom:1px solid #e2e8f0;'><span style='color:#64748b; font-size:13px; font-family:Tahoma,Arial,sans-serif;'>مبلغ پرداختی:</span><br><strong style='color:#059669; font-size:18px; font-family:Tahoma,Arial,sans-serif;'>{{amount}} تومان</strong></td></tr>" .
                        "<tr><td style='padding:14px 20px;'><span style='color:#64748b; font-size:13px; font-family:Tahoma,Arial,sans-serif;'>تاریخ تراکنش:</span><br><strong style='color:#1e293b; font-size:15px; font-family:Tahoma,Arial,sans-serif;'>{{date}}</strong></td></tr>" .
                        "</table></td></tr>" .
                        "<tr><td style='padding:20px 30px; text-align:center;'><p style='margin:0; color:#64748b; font-size:13px; font-family:Tahoma,Arial,sans-serif;'>از اعتماد شما سپاسگزاریم. 🙏</p></td></tr>" .
                        $emailBodyClose() .
                        $emailFooter('{{app_name}}'),
                        json_encode(['name', 'plan_name', 'amount', 'date', 'app_name'], JSON_UNESCAPED_UNICODE),
                        1,
                    ],
                    [
                        'subscription_expiry',
                        'یادآوری انقضای اشتراک',
                        'یادآوری: اشتراک {{plan_name}} شما تا {{days_left}} روز دیگر منقضی می‌شود',
                        $emailHeader('یادآوری انقضای اشتراک') .
                        $emailBodyOpen('اشتراک شما در حال انقضا است') .
                        "<tr><td style='background:linear-gradient(135deg,#78350f 0%,#b45309 50%,#f59e0b 100%); padding:40px 30px; text-align:center;'>" .
                        "<h1 style='margin:0; color:#ffffff; font-size:26px; font-family:Tahoma,Arial,sans-serif;'>⏰ یادآوری انقضای اشتراک</h1>" .
                        "<p style='margin:8px 0 0; color:rgba(255,255,255,0.85); font-size:14px; font-family:Tahoma,Arial,sans-serif;'>اشتراک شما به زودی به پایان می‌رسد</p>" .
                        "</td></tr>" .
                        "<tr><td style='padding:35px 30px 10px;'>" .
                        "<h2 style='margin:0 0 8px; color:#1e293b; font-size:20px; font-family:Tahoma,Arial,sans-serif;'>سلام {{name}} عزیز،</h2>" .
                        "<p style='margin:0; color:#475569; font-size:14px; line-height:2; font-family:Tahoma,Arial,sans-serif;'>اشتراک <strong style='color:#d97706;'>{{plan_name}}</strong> شما تنها <strong style='color:#dc2626; font-size:18px;'>{{days_left}} روز</strong> دیگر اعتبار دارد.</p>" .
                        "</td></tr>" .
                        "<tr><td style='padding:10px 30px; text-align:center;'>" .
                        "<table role='presentation' width='100%' cellpadding='0' cellspacing='0'><tr><td style='padding:15px; background:#fffbeb; border:1px solid #fde68a; border-radius:10px; text-align:center;'>" .
                        "<p style='margin:0; color:#92400e; font-size:14px; font-family:Tahoma,Arial,sans-serif;'>⚠️ برای جلوگیری از قطع سرویس، لطفاً اشتراک خود را تمدید کنید.</p>" .
                        "</td></tr></table></td></tr>" .
                        $ctaButton('{{app_url}}', 'تمدید اشتراک') .
                        $emailBodyClose() .
                        $emailFooter('{{app_name}}'),
                        json_encode(['name', 'days_left', 'plan_name', 'app_url', 'app_name'], JSON_UNESCAPED_UNICODE),
                        1,
                    ],
                    [
                        'subscription_expired',
                        'انقضای اشتراک',
                        'اشتراک شما در {{app_name}} منقضی شده است',
                        $emailHeader('انقضای اشتراک') .
                        $emailBodyOpen('اشتراک شما به پایان رسیده است') .
                        "<tr><td style='background:linear-gradient(135deg,#7f1d1d 0%,#b91c1c 50%,#ef4444 100%); padding:40px 30px; text-align:center;'>" .
                        "<h1 style='margin:0; color:#ffffff; font-size:26px; font-family:Tahoma,Arial,sans-serif;'>🔴 انقضای اشتراک</h1>" .
                        "<p style='margin:8px 0 0; color:rgba(255,255,255,0.85); font-size:14px; font-family:Tahoma,Arial,sans-serif;'>اشتراک شما به پایان رسیده است</p>" .
                        "</td></tr>" .
                        "<tr><td style='padding:35px 30px 10px;'>" .
                        "<h2 style='margin:0 0 8px; color:#1e293b; font-size:20px; font-family:Tahoma,Arial,sans-serif;'>سلام {{name}} عزیز،</h2>" .
                        "<p style='margin:0; color:#475569; font-size:14px; line-height:2; font-family:Tahoma,Arial,sans-serif;'>متأسفانه اشتراک شما در <strong style='color:#4f46e5;'>{{app_name}}</strong> به پایان رسیده است. برای ادامه استفاده از امکانات پلتفرم، لطفاً اشتراک خود را تمدید کنید.</p>" .
                        "</td></tr>" .
                        "<tr><td style='padding:10px 30px; text-align:center;'>" .
                        "<table role='presentation' width='100%' cellpadding='0' cellspacing='0'><tr><td style='padding:15px; background:#fef2f2; border:1px solid #fecaca; border-radius:10px; text-align:center;'>" .
                        "<p style='margin:0; color:#991b1b; font-size:14px; font-family:Tahoma,Arial,sans-serif;'>🔒 در حال حاضر دسترسی شما به امکانات پلتفرم محدود شده است.</p>" .
                        "</td></tr></table></td></tr>" .
                        $ctaButton('{{app_url}}', 'تمدید فوری اشتراک') .
                        $emailBodyClose() .
                        $emailFooter('{{app_name}}'),
                        json_encode(['name', 'app_url', 'app_name'], JSON_UNESCAPED_UNICODE),
                        1,
                    ],
                    [
                        'password_reset',
                        'بازنشانی رمز عبور',
                        'بازنشانی رمز عبور — {{app_name}}',
                        $emailHeader('بازنشانی رمز عبور') .
                        $emailBodyOpen('درخواست بازنشانی رمز عبور') .
                        "<tr><td style='background:linear-gradient(135deg,#312e81 0%,#4f46e5 50%,#6366f1 100%); padding:40px 30px; text-align:center;'>" .
                        "<h1 style='margin:0; color:#ffffff; font-size:26px; font-family:Tahoma,Arial,sans-serif;'>🔑 بازنشانی رمز عبور</h1>" .
                        "<p style='margin:8px 0 0; color:rgba(255,255,255,0.85); font-size:14px; font-family:Tahoma,Arial,sans-serif;'>درخواست تغییر کلمه عبور شما دریافت شد</p>" .
                        "</td></tr>" .
                        "<tr><td style='padding:35px 30px 10px;'>" .
                        "<h2 style='margin:0 0 8px; color:#1e293b; font-size:20px; font-family:Tahoma,Arial,sans-serif;'>سلام {{name}} عزیز،</h2>" .
                        "<p style='margin:0; color:#475569; font-size:14px; line-height:2; font-family:Tahoma,Arial,sans-serif;'>برای تنظیم رمز عبور جدید، روی دکمه زیر کلیک کنید:</p>" .
                        "</td></tr>" .
                        $ctaButton('{{reset_link}}', 'بازنشانی کلمه عبور') .
                        "<tr><td style='padding:10px 30px 20px; text-align:center;'>" .
                        "<table role='presentation' width='100%' cellpadding='0' cellspacing='0'><tr><td style='padding:15px; background:#fefce8; border:1px solid #fde68a; border-radius:10px; text-align:center;'>" .
                        "<p style='margin:0; color:#854d0e; font-size:13px; font-family:Tahoma,Arial,sans-serif;'>⚠️ این لینک فقط ۱ ساعت اعتبار دارد. اگر شما درخواست نکرده‌اید، این پیام را نادیده بگیرید.</p>" .
                        "</td></tr></table></td></tr>" .
                        $emailBodyClose() .
                        $emailFooter('{{app_name}}'),
                        json_encode(['name', 'reset_link', 'app_name'], JSON_UNESCAPED_UNICODE),
                        1,
                    ],
                    [
                        'ticket_reply',
                        'پاسخ جدید به تیکت',
                        'پاسخ جدید به تیکت شما: {{ticket_subject}}',
                        $emailHeader('پاسخ به تیکت') .
                        $emailBodyOpen('پاسخ جدید به تیکت پشتیبانی شما') .
                        "<tr><td style='background:linear-gradient(135deg,#312e81 0%,#4f46e5 50%,#6366f1 100%); padding:40px 30px; text-align:center;'>" .
                        "<h1 style='margin:0; color:#ffffff; font-size:26px; font-family:Tahoma,Arial,sans-serif;'>🎫 پاسخ جدید به تیکت</h1>" .
                        "<p style='margin:8px 0 0; color:rgba(255,255,255,0.85); font-size:14px; font-family:Tahoma,Arial,sans-serif;'>تیکت پشتیبانی شما پاسخ داده شده است</p>" .
                        "</td></tr>" .
                        "<tr><td style='padding:35px 30px 10px;'>" .
                        "<h2 style='margin:0 0 8px; color:#1e293b; font-size:20px; font-family:Tahoma,Arial,sans-serif;'>سلام {{name}} عزیز،</h2>" .
                        "<p style='margin:0; color:#475569; font-size:14px; line-height:2; font-family:Tahoma,Arial,sans-serif;'>تیکت شما با موضوع <strong style='color:#4f46e5;'>{{ticket_subject}}</strong> پاسخ داده شده است.</p>" .
                        "</td></tr>" .
                        "<tr><td style='padding:10px 30px; text-align:center;'><p style='margin:0; color:#64748b; font-size:13px; font-family:Tahoma,Arial,sans-serif;'>برای مشاهده پاسخ و ادامه گفتگو، روی دکمه زیر کلیک کنید:</p></td></tr>" .
                        $ctaButton('{{app_url}}', 'مشاهده تیکت') .
                        $emailBodyClose() .
                        $emailFooter('{{app_name}}'),
                        json_encode(['name', 'ticket_subject', 'app_url', 'app_name'], JSON_UNESCAPED_UNICODE),
                        1,
                    ],
                    [
                        'custom_notification',
                        'اعلان عمومی',
                        'اعلان جدید از {{app_name}}',
                        $emailHeader('اعلان جدید') .
                        $emailBodyOpen('اعلان جدید از سوی مدیریت پلتفرم') .
                        "<tr><td style='background:linear-gradient(135deg,#312e81 0%,#4f46e5 50%,#6366f1 100%); padding:40px 30px; text-align:center;'>" .
                        "<h1 style='margin:0; color:#ffffff; font-size:26px; font-family:Tahoma,Arial,sans-serif;'>📢 اعلان جدید</h1>" .
                        "<p style='margin:8px 0 0; color:rgba(255,255,255,0.85); font-size:14px; font-family:Tahoma,Arial,sans-serif;'>اعلان جدیدی از {{app_name}} دریافت کرده‌اید</p>" .
                        "</td></tr>" .
                        "<tr><td style='padding:35px 30px 10px;'>" .
                        "<h2 style='margin:0 0 8px; color:#1e293b; font-size:20px; font-family:Tahoma,Arial,sans-serif;'>سلام {{name}} عزیز،</h2>" .
                        "<p style='margin:0; color:#475569; font-size:14px; line-height:2; font-family:Tahoma,Arial,sans-serif;'>{{message}}</p>" .
                        "</td></tr>" .
                        "<tr><td style='padding:10px 30px; text-align:center;'><p style='margin:0; color:#64748b; font-size:13px; font-family:Tahoma,Arial,sans-serif;'>برای اطلاعات بیشتر، وارد پنل کاربری خود شوید:</p></td></tr>" .
                        $ctaButton('{{app_url}}', 'ورود به پنل کاربری') .
                        $emailBodyClose() .
                        $emailFooter('{{app_name}}'),
                        json_encode(['name', 'message', 'app_url', 'app_name'], JSON_UNESCAPED_UNICODE),
                        1,
                    ],
                ];

                foreach ($defaults as $row) {
                    try {
                        $stmt = $db->prepare("INSERT OR IGNORE INTO email_templates (event_key, template_name, subject, body_html, variables, is_active) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute($row);
                    } catch (\Exception $e) {
                        try {
                            $stmt = $db->prepare("INSERT IGNORE INTO email_templates (event_key, template_name, subject, body_html, variables, is_active) VALUES (?, ?, ?, ?, ?, ?)");
                            $stmt->execute($row);
                        } catch (\Exception $e2) {}
                    }
                }
            },

            'v6_link_tracking' => function($db) {
                $driver = self::getConfig('database.driver', 'sqlite');

                try {
                    if ($driver === 'mysql') {
                        $db->exec("CREATE TABLE IF NOT EXISTS link_tracking (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(20) NOT NULL UNIQUE, original_url TEXT NOT NULL, post_id INT NOT NULL, channel_id INT NOT NULL, tenant_id INT NOT NULL, total_clicks INT DEFAULT 0, unique_clicks INT DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (post_id) REFERENCES posts(id), FOREIGN KEY (channel_id) REFERENCES channels(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                        $db->exec("CREATE TABLE IF NOT EXISTS link_clicks (id INT AUTO_INCREMENT PRIMARY KEY, link_id INT NOT NULL, ip_address VARCHAR(45), user_agent TEXT, referer TEXT, is_unique INT DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (link_id) REFERENCES link_tracking(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                        $db->exec("CREATE TABLE IF NOT EXISTS verification_codes (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, type VARCHAR(20) NOT NULL, code VARCHAR(10) NOT NULL, expires_at DATETIME NOT NULL, used INT DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                    } else {
                        $db->exec("CREATE TABLE IF NOT EXISTS link_tracking (id INTEGER PRIMARY KEY AUTOINCREMENT, code VARCHAR(20) NOT NULL UNIQUE, original_url TEXT NOT NULL, post_id INTEGER NOT NULL, channel_id INTEGER NOT NULL, tenant_id INTEGER NOT NULL, total_clicks INTEGER DEFAULT 0, unique_clicks INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (post_id) REFERENCES posts(id), FOREIGN KEY (channel_id) REFERENCES channels(id));");
                        $db->exec("CREATE TABLE IF NOT EXISTS link_clicks (id INTEGER PRIMARY KEY AUTOINCREMENT, link_id INTEGER NOT NULL, ip_address VARCHAR(45), user_agent TEXT, referer TEXT, is_unique INTEGER DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (link_id) REFERENCES link_tracking(id));");
                        $db->exec("CREATE TABLE IF NOT EXISTS verification_codes (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, type VARCHAR(20) NOT NULL, code VARCHAR(10) NOT NULL, expires_at DATETIME NOT NULL, used INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(id));");
                    }
                } catch (\Exception $e) {}
            },

            'v7_birthday_column' => function($db) {
                try { $db->exec("ALTER TABLE users ADD COLUMN birthday VARCHAR(10) NULL"); } catch (\Exception $e) {}
            },

            'v8_scheduled_posts_target_channels' => function($db) {
                // افزودن ستون target_channels برای ذخیره لیست کانال‌های هدف پست‌های زمان‌بندی‌شده
                try { $db->exec("ALTER TABLE posts ADD COLUMN target_channels TEXT"); } catch (\Exception $e) {}
                // افزودن ستون expiry_reminder_sent برای جلوگیری از ارسال تکراری یادآوری انقضا
                try { $db->exec("ALTER TABLE subscriptions ADD COLUMN expiry_reminder_sent INTEGER DEFAULT 0"); } catch (\Exception $e) {}
            },
            'v9_create_plans_table' => function($db) {
                // ایجاد جدول plans در صورتی که از ابتدا وجود نداشته باشد
                $driver = self::getConfig('database.driver', 'sqlite');
                try {
                    if ($driver === 'mysql') {
                        $db->exec("CREATE TABLE IF NOT EXISTS plans (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            title VARCHAR(100) NOT NULL,
                            price DECIMAL(12,2) DEFAULT 0.00,
                            duration_days INT DEFAULT 30,
                            max_channels INT DEFAULT 1,
                            max_posts INT DEFAULT 10,
                            features TEXT,
                            payment_url TEXT NULL,
                            image_url TEXT NULL,
                            description TEXT NULL,
                            early_renewal_discount INT DEFAULT 0,
                            general_discount INT DEFAULT 0,
                            discount_badge_text VARCHAR(150) NULL,
                            is_featured INT DEFAULT 0,
                            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                    } else {
                        $db->exec("CREATE TABLE IF NOT EXISTS plans (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            title VARCHAR(100) NOT NULL,
                            price DECIMAL(12,2) DEFAULT 0.00,
                            duration_days INTEGER DEFAULT 30,
                            max_channels INTEGER DEFAULT 1,
                            max_posts INTEGER DEFAULT 10,
                            features TEXT,
                            payment_url TEXT NULL,
                            image_url TEXT NULL,
                            description TEXT NULL,
                            early_renewal_discount INTEGER DEFAULT 0,
                            general_discount INTEGER DEFAULT 0,
                            discount_badge_text VARCHAR(150) NULL,
                            is_featured INTEGER DEFAULT 0,
                            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                        );");
                    }
                } catch (\Exception $e) {}
            },

            'v10_ticket_categories_and_agents' => function($db) {
                // ایجاد جدول دسته‌بندی تیکت‌ها
                try {
                    $db->exec("CREATE TABLE IF NOT EXISTS ticket_categories (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        slug VARCHAR(100) NOT NULL UNIQUE,
                        title VARCHAR(150) NOT NULL,
                        icon VARCHAR(50) DEFAULT '🌐',
                        assigned_agent_id INTEGER NULL,
                        sort_order INTEGER DEFAULT 0,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    )");
                } catch (\Exception $e) {}

                // ستون‌های جدید tickets: priority و created_by_admin
                try { $db->exec("ALTER TABLE tickets ADD COLUMN priority VARCHAR(20) DEFAULT 'normal'"); } catch (\Exception $e) {}
                try { $db->exec("ALTER TABLE tickets ADD COLUMN created_by_admin INTEGER DEFAULT 0"); } catch (\Exception $e) {}

                // دسته‌بندی‌های پیش‌فرض اگر خالی بود
                try {
                    $count = $db->query("SELECT COUNT(*) FROM ticket_categories")->fetchColumn();
                    if ($count == 0) {
                        $db->exec("INSERT INTO ticket_categories (slug, title, icon, sort_order) VALUES ('technical', 'فنی و ربات‌ها 🤖', '🤖', 1)");
                        $db->exec("INSERT INTO ticket_categories (slug, title, icon, sort_order) VALUES ('billing', 'مالی و فیش واریزی 💳', '💳', 2)");
                        $db->exec("INSERT INTO ticket_categories (slug, title, icon, sort_order) VALUES ('general', 'سوال عمومی 🌐', '🌐', 3)");
                    }
                } catch (\Exception $e) {}
            },

            'v11_responder_logs_table' => function($db) {
                try {
                    $db->exec("CREATE TABLE IF NOT EXISTS responder_logs (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        tenant_id INTEGER NOT NULL,
                        channel_id INTEGER NULL,
                        sender_id VARCHAR(100) DEFAULT '',
                        sender_name VARCHAR(200) DEFAULT '',
                        message_text TEXT DEFAULT '',
                        matched_keyword VARCHAR(255) DEFAULT '',
                        reply_sent INTEGER DEFAULT 0,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    )");
                } catch (\Exception $e) {}
            },
            'v12_notifications_table' => function($db) {
                // v12 was originally written with SQLite-only AUTOINCREMENT syntax.
                // That failure was swallowed, which left MySQL installations without
                // the notifications table while the migration was still recorded as OK.
                // Keep both drivers explicit and never swallow a schema failure.
                $driver = self::getConfig('database.driver', 'sqlite');
                if ($driver === 'mysql') {
                    $db->exec("CREATE TABLE IF NOT EXISTS notifications (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        type VARCHAR(50) NOT NULL DEFAULT 'general',
                        title VARCHAR(255) NOT NULL,
                        message TEXT NULL,
                        target_section VARCHAR(100) NOT NULL DEFAULT '',
                        is_read TINYINT(1) NOT NULL DEFAULT 0,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_notifications_user_read(user_id, is_read),
                        INDEX idx_notifications_user_created(user_id, created_at),
                        CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                } else {
                    $db->exec("CREATE TABLE IF NOT EXISTS notifications (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id INTEGER NOT NULL,
                        type VARCHAR(50) NOT NULL DEFAULT 'general',
                        title TEXT NOT NULL,
                        message TEXT DEFAULT '',
                        target_section VARCHAR(100) DEFAULT '',
                        is_read INTEGER DEFAULT 0,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    )");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_notifications_user_read ON notifications(user_id, is_read)");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_notifications_user_created ON notifications(user_id, created_at DESC)");
                }
            },
            'v13_concurrency_indexes' => function($db) {
                // ایندکس‌های hot-path برای کاهش lock contention و full scan در سناریوهای همزمان.
                // ساخت ایندکس idempotent است؛ در MySQL IF NOT EXISTS برای CREATE INDEX قابل اتکا نیست،
                // بنابراین هر ایندکس در try/catch مستقل ساخته می‌شود.
                $indexes = [
                    'idx_payments_status_created' => 'payments(status, created_at)',
                    'idx_subscriptions_user_status' => 'subscriptions(user_id, status)',
                    'idx_subscriptions_end_status' => 'subscriptions(status, end_date)',
                    'idx_wallet_transactions_user_created' => 'wallet_transactions(user_id, created_at)',
                    'idx_referrals_referred_status' => 'referrals(referred_id, status)',
                    'idx_rate_limits_action_ip' => 'rate_limits(action, ip)',
                ];
                foreach ($indexes as $name => $definition) {
                    try {
                        $db->exec("CREATE INDEX $name ON $definition");
                    } catch (\Throwable $e) {
                        // Index may already exist on an upgraded database.
                    }
                }
            },
            'v14_anti_abuse_idempotency' => function($db) {
                $driver = self::getConfig('database.driver', 'sqlite');
                if ($driver === 'mysql') {
                    $db->exec("CREATE TABLE IF NOT EXISTS anti_abuse_claims (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        claim_type VARCHAR(50) NOT NULL,
                        identity_hash CHAR(64) NOT NULL,
                        user_id INT NULL,
                        metadata TEXT NULL,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY uq_anti_abuse_claim (claim_type, identity_hash),
                        INDEX idx_anti_abuse_user (user_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                    $db->exec("CREATE TABLE IF NOT EXISTS idempotency_keys (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        operation VARCHAR(80) NOT NULL,
                        idem_key VARCHAR(128) NOT NULL,
                        status VARCHAR(20) NOT NULL DEFAULT 'processing',
                        resource_id INT NULL,
                        response_json LONGTEXT NULL,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY uq_idempotency (user_id, operation, idem_key),
                        INDEX idx_idempotency_created (created_at),
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                    // Backfill immutable channel claims from the legacy registry.
                    $db->exec("INSERT IGNORE INTO anti_abuse_claims (claim_type, identity_hash, user_id, metadata, created_at)
                        SELECT 'channel', SHA2(CONCAT(LOWER(platform), CHAR(0), channel_id), 256), owner_user_id,
                               JSON_OBJECT('platform', platform, 'channel_id', channel_id), created_at
                        FROM channel_registry");
                    $db->exec("INSERT IGNORE INTO anti_abuse_claims (claim_type, identity_hash, user_id, metadata, created_at)
                        SELECT 'free_trial_phone', SHA2(CONCAT('free_trial_phone', CHAR(0), phone), 256), id,
                               JSON_OBJECT('phone', phone), created_at
                        FROM users WHERE phone IS NOT NULL AND phone <> ''");
                } else {
                    $db->exec("CREATE TABLE IF NOT EXISTS anti_abuse_claims (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        claim_type VARCHAR(50) NOT NULL,
                        identity_hash VARCHAR(64) NOT NULL,
                        user_id INTEGER NULL,
                        metadata TEXT NULL,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE(claim_type, identity_hash)
                    )");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_anti_abuse_user ON anti_abuse_claims(user_id)");
                    $db->exec("CREATE TABLE IF NOT EXISTS idempotency_keys (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id INTEGER NOT NULL,
                        operation VARCHAR(80) NOT NULL,
                        idem_key VARCHAR(128) NOT NULL,
                        status VARCHAR(20) NOT NULL DEFAULT 'processing',
                        resource_id INTEGER NULL,
                        response_json TEXT NULL,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE(user_id, operation, idem_key),
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    )");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_idempotency_created ON idempotency_keys(created_at)");
                    // SQLite SHA2 is not built-in; PHP backfill keeps this migration portable.
                    try {
                        $rows = $db->query("SELECT platform, channel_id, owner_user_id, created_at FROM channel_registry")->fetchAll();
                        $ins = $db->prepare("INSERT OR IGNORE INTO anti_abuse_claims (claim_type, identity_hash, user_id, metadata, created_at) VALUES (?, ?, ?, ?, ?)");
                        foreach ($rows as $r) {
                            $identity = strtolower(trim($r['platform'])) . "\0" . trim($r['channel_id']);
                            $ins->execute(['channel', hash('sha256', 'channel' . "\0" . $identity), (int)$r['owner_user_id'], json_encode(['platform'=>$r['platform'],'channel_id'=>$r['channel_id']], JSON_UNESCAPED_UNICODE), $r['created_at']]);
                        }
                        $rows = $db->query("SELECT id, phone, created_at FROM users WHERE phone IS NOT NULL AND phone <> ''")->fetchAll();
                        foreach ($rows as $r) {
                            $phone = \WHCM\Domain\AntiAbuse::normalizePhone((string)$r['phone']);
                            $ins->execute(['free_trial_phone', hash('sha256', 'free_trial_phone' . "\0" . $phone), (int)$r['id'], json_encode(['phone'=>$phone], JSON_UNESCAPED_UNICODE), $r['created_at']]);
                        }
                    } catch (\Throwable $e) {
                        error_log('[Postyar] v14 anti-abuse backfill failed: ' . $e->getMessage());
                    }
                }
            },
            'v15_auth_otp_hardening' => function($db) {
                // Rate-limit buckets must be unique so concurrent OTP/login attempts cannot create duplicate rows.
                try {
                    $db->exec("DELETE FROM rate_limits WHERE id NOT IN (SELECT MIN(id) FROM rate_limits GROUP BY ip, action)");
                } catch (\Throwable $e) {}
                // Fresh MySQL installs may already contain these v15 indexes because
                // install_mysql.sql historically shipped part of the hardening schema.
                // Never treat an already-existing equivalent index as a migration failure.
                $indexExists = static function (string $table, string $index) use ($db): bool {
                    $stmt = $db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1");
                    $stmt->execute([$table, $index]);
                    return (bool) $stmt->fetchColumn();
                };

                if (!$indexExists('rate_limits', 'uq_rate_limits_ip_action')) {
                    $db->exec("CREATE UNIQUE INDEX uq_rate_limits_ip_action ON rate_limits(ip, action)");
                }
                if (!$indexExists('verification_codes', 'idx_verification_user_type_used')) {
                    $db->exec("CREATE INDEX idx_verification_user_type_used ON verification_codes(user_id, type, used, created_at)");
                }

                // Normalize legacy phone numbers before enforcing uniqueness.
                try {
                    $rows = $db->query("SELECT id, phone FROM users WHERE phone IS NOT NULL AND phone <> ''")->fetchAll();
                    $update = $db->prepare('UPDATE users SET phone = ? WHERE id = ?');
                    foreach ($rows as $row) {
                        $phone = \WHCM\Domain\AntiAbuse::normalizePhone((string)$row['phone']);
                        if ($phone) $update->execute([$phone, (int)$row['id']]);
                    }
                    $dupes = $db->query("SELECT phone, COUNT(*) c FROM users WHERE phone IS NOT NULL AND phone <> '' GROUP BY phone HAVING COUNT(*) > 1")->fetchAll();
                    if (!empty($dupes)) throw new \RuntimeException('Duplicate normalized phone numbers must be resolved before v15 unique phone index.');
                } catch (\Throwable $e) {
                    error_log('[Postyar] v15 phone normalization/index check: ' . $e->getMessage());
                    if ($e instanceof \RuntimeException) throw $e;
                }
                if (!$indexExists('users', 'uq_users_phone')) {
                    $db->exec("CREATE UNIQUE INDEX uq_users_phone ON users(phone)");
                }
            },
            'v16_tenant_isolation_webhook_hardening' => function($db) {
                $driver = self::getConfig('database.driver', 'sqlite');

                // Ensure mobile API authentication is self-contained on fresh installs.
                if ($driver === 'mysql') {
                    $db->exec("CREATE TABLE IF NOT EXISTS api_tokens (
                        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        token_hash CHAR(64) NOT NULL UNIQUE,
                        device_name VARCHAR(100) NULL,
                        created_at DATETIME NOT NULL,
                        last_used_at DATETIME NULL,
                        expires_at DATETIME NOT NULL,
                        revoked_at DATETIME NULL,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                        INDEX idx_api_tokens_user_active (user_id, revoked_at, expires_at),
                        INDEX idx_api_tokens_expires (expires_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                } else {
                    $db->exec("CREATE TABLE IF NOT EXISTS api_tokens (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id INTEGER NOT NULL,
                        token_hash CHAR(64) NOT NULL UNIQUE,
                        device_name VARCHAR(100) NULL,
                        created_at DATETIME NOT NULL,
                        last_used_at DATETIME NULL,
                        expires_at DATETIME NOT NULL,
                        revoked_at DATETIME NULL,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    )");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_api_tokens_user_active ON api_tokens(user_id, revoked_at, expires_at)");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_api_tokens_expires ON api_tokens(expires_at)");
                }
                // High-value ownership/query indexes for 5k–10k user workloads.
                if ($driver === 'mysql') {
                    $indexes = [
                        'CREATE INDEX idx_channels_tenant_id ON channels(tenant_id, id)',
                        'CREATE INDEX idx_channels_tenant_platform_id ON channels(tenant_id, platform, channel_id)',
                        'CREATE INDEX idx_posts_tenant_status_id ON posts(tenant_id, status, id)',
                        'CREATE INDEX idx_posts_tenant_scheduled ON posts(tenant_id, status, scheduled_at)',
                        'CREATE INDEX idx_tickets_user_id_id ON tickets(user_id, id)',
                        'CREATE INDEX idx_payments_user_id_id ON payments(user_id, id)',
                        'CREATE INDEX idx_link_tracking_tenant_id_id ON link_tracking(tenant_id, id)',
                        'CREATE INDEX idx_auto_replies_tenant_channel_id ON auto_replies(tenant_id, channel_id, id)',
                    ];
                    foreach ($indexes as $sql) {
                        try { $db->exec($sql); } catch (\Throwable $e) { if (stripos($e->getMessage(), 'duplicate') === false && stripos($e->getMessage(), 'already exists') === false) { error_log('[Postyar] v16 index warning: ' . $e->getMessage()); } }
                    }
                } else {
                    $indexes = [
                        'CREATE INDEX IF NOT EXISTS idx_channels_tenant_id ON channels(tenant_id, id)',
                        'CREATE INDEX IF NOT EXISTS idx_channels_tenant_platform_id ON channels(tenant_id, platform, channel_id)',
                        'CREATE INDEX IF NOT EXISTS idx_posts_tenant_status_id ON posts(tenant_id, status, id)',
                        'CREATE INDEX IF NOT EXISTS idx_posts_tenant_scheduled ON posts(tenant_id, status, scheduled_at)',
                        'CREATE INDEX IF NOT EXISTS idx_tickets_user_id_id ON tickets(user_id, id)',
                        'CREATE INDEX IF NOT EXISTS idx_payments_user_id_id ON payments(user_id, id)',
                        'CREATE INDEX IF NOT EXISTS idx_link_tracking_tenant_id_id ON link_tracking(tenant_id, id)',
                        'CREATE INDEX IF NOT EXISTS idx_auto_replies_tenant_channel_id ON auto_replies(tenant_id, channel_id, id)',
                    ];
                    foreach ($indexes as $sql) { try { $db->exec($sql); } catch (\Throwable $e) {} }
                }

                // Existing webhook endpoints without a secret are unsafe. Generate a cryptographic secret
                // and disable the legacy webhook until ChannelManager::setWebhook() re-registers it remotely.
                try {
                    $rows = $db->query("SELECT id, tenant_id FROM channels WHERE webhook_secret IS NULL OR webhook_secret = ''")->fetchAll();
                    $update = $db->prepare('UPDATE channels SET webhook_secret = ?, webhook_active = 0 WHERE id = ? AND tenant_id = ?');
                    foreach ($rows as $row) {
                        $secret = bin2hex(random_bytes(32));
                        $update->execute([$secret, (int)$row['id'], (int)$row['tenant_id']]);
                    }
                } catch (\Throwable $e) {
                    error_log('[Postyar] v16 webhook secret migration failed: ' . $e->getMessage());
                    throw $e;
                }
            },
            'v17_concurrency_delivery_integrity' => function($db) {
                $driver = self::getConfig('database.driver', 'sqlite');
                try {
                    $db->exec("DELETE FROM channel_messages WHERE id NOT IN (SELECT MAX(id) FROM channel_messages GROUP BY post_id, channel_id)");
                    if ($driver === 'mysql') {
                        try { $db->exec("CREATE UNIQUE INDEX uq_channel_messages_post_channel ON channel_messages(post_id, channel_id)"); } catch (\Throwable $e) {}
                        try { $db->exec("CREATE INDEX idx_channel_messages_status ON channel_messages(status, post_id, channel_id)"); } catch (\Throwable $e) {}
                    } else {
                        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_channel_messages_post_channel ON channel_messages(post_id, channel_id)");
                        $db->exec("CREATE INDEX IF NOT EXISTS idx_channel_messages_status ON channel_messages(status, post_id, channel_id)");
                    }
                } catch (\Throwable $e) {
                    error_log('[Postyar] v17 delivery integrity migration failed: ' . $e->getMessage());
                    throw $e;
                }
            },
            'v18_financial_integrity' => function($db) {
                $driver = self::getConfig('database.driver', 'sqlite');

                // Preserve the amount quoted to the customer at submission time.
                try {
                    $db->exec($driver === 'mysql'
                        ? "ALTER TABLE payments ADD COLUMN quoted_amount DECIMAL(12,2) NULL AFTER amount"
                        : "ALTER TABLE payments ADD COLUMN quoted_amount DECIMAL(12,2) NULL");
                } catch (\Throwable $e) { /* already exists */ }
                try { $db->exec("UPDATE payments SET quoted_amount = amount WHERE quoted_amount IS NULL"); } catch (\Throwable $e) {}

                // Collapse legacy duplicates before adding hard uniqueness. Keep the oldest record.
                try {
                    if ($driver === 'mysql') {
                        $db->exec("DELETE p1 FROM payments p1 INNER JOIN payments p2 ON p1.user_id = p2.user_id AND p1.reference_num = p2.reference_num AND p1.id > p2.id WHERE p1.reference_num IS NOT NULL AND p1.reference_num <> ''");
                        $db->exec("DELETE w1 FROM wallet_transactions w1 INNER JOIN wallet_transactions w2 ON w1.user_id = w2.user_id AND w1.reference_type = w2.reference_type AND w1.reference_id = w2.reference_id AND w1.type = w2.type AND w1.id > w2.id WHERE w1.reference_type IS NOT NULL AND w1.reference_id IS NOT NULL");
                    } else {
                        $db->exec("DELETE FROM payments WHERE reference_num IS NOT NULL AND reference_num <> '' AND id NOT IN (SELECT MIN(id) FROM payments WHERE reference_num IS NOT NULL AND reference_num <> '' GROUP BY user_id, reference_num)");
                        $db->exec("DELETE FROM wallet_transactions WHERE reference_type IS NOT NULL AND reference_id IS NOT NULL AND id NOT IN (SELECT MIN(id) FROM wallet_transactions WHERE reference_type IS NOT NULL AND reference_id IS NOT NULL GROUP BY user_id, reference_type, reference_id, type)");
                    }
                } catch (\Throwable $e) {
                    error_log('[Postyar] v18 duplicate cleanup failed: ' . $e->getMessage());
                    throw $e;
                }

                // Enforce unique reference numbers per user at the DB boundary. Empty legacy refs are excluded.
                try {
                    if ($driver === 'sqlite') {
                        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_payments_user_reference ON payments(user_id, reference_num) WHERE reference_num IS NOT NULL AND reference_num <> ''");
                    } else {
                        $exists = $db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='payments' AND INDEX_NAME='uq_payments_user_reference' LIMIT 1");
                        $exists->execute();
                        if (!$exists->fetchColumn()) {
                            $db->exec("CREATE UNIQUE INDEX uq_payments_user_reference ON payments(user_id, reference_num)");
                        }
                    }
                } catch (\Throwable $e) {
                    error_log('[Postyar] v18 payment reference uniqueness not applied: ' . $e->getMessage());
                    throw $e;
                }

                // Ledger rows created by referral rewards must be unique by business reference.
                try {
                    if ($driver === 'sqlite') {
                        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_wallet_reference ON wallet_transactions(user_id, reference_type, reference_id, type) WHERE reference_type IS NOT NULL AND reference_id IS NOT NULL");
                    } else {
                        $exists = $db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='wallet_transactions' AND INDEX_NAME='uq_wallet_reference' LIMIT 1");
                        $exists->execute();
                        if (!$exists->fetchColumn()) {
                            $db->exec("CREATE UNIQUE INDEX uq_wallet_reference ON wallet_transactions(user_id, reference_type, reference_id, type)");
                        }
                    }
                } catch (\Throwable $e) {
                    error_log('[Postyar] v18 wallet reference uniqueness not applied: ' . $e->getMessage());
                    throw $e;
                }
            },
            'v19_entitlement_identity_integrity' => function($db) {
                // Canonicalize historical channel identities so @Foo/Foo and case variants
                // cannot bypass the immutable anti-abuse claim after an upgrade.
                try {
                    $rows = $db->query("SELECT id, platform, channel_id, owner_user_id FROM channel_registry ORDER BY id ASC")->fetchAll();
                    $seen = [];
                    foreach ($rows as $row) {
                        $platform = AntiAbuse::normalizePlatform((string)$row['platform']);
                        $channelId = AntiAbuse::normalizeChannelId((string)$row['channel_id']);
                        $key = $platform . "\0" . $channelId;
                        if (isset($seen[$key])) {
                            if ((int)$seen[$key]['owner_user_id'] !== (int)$row['owner_user_id']) {
                                throw new \RuntimeException('Canonical channel identity collision requires manual resolution: ' . $platform . '/' . $channelId);
                            }
                            // Same owner duplicate: remove the redundant registry row before uniqueness is enforced.
                            $db->prepare('DELETE FROM channel_registry WHERE id = ?')->execute([(int)$row['id']]);
                            continue;
                        }
                        $seen[$key] = $row;
                        $db->prepare('UPDATE channel_registry SET platform = ?, channel_id = ? WHERE id = ?')
                           ->execute([$platform, $channelId, (int)$row['id']]);
                    }

                    // Rebuild channel claims from canonical registry identities. Conflicting owners fail closed.
                    $claims = $db->query("SELECT id, claim_type, identity_hash, user_id, metadata FROM anti_abuse_claims WHERE claim_type = 'channel' ORDER BY id ASC")->fetchAll();
                    $claimSeen = [];
                    foreach ($claims as $claim) {
                        $meta = json_decode((string)($claim['metadata'] ?? ''), true);
                        if (!is_array($meta) || empty($meta['platform']) || !isset($meta['channel_id'])) continue;
                        $platform = AntiAbuse::normalizePlatform((string)$meta['platform']);
                        $channelId = AntiAbuse::normalizeChannelId((string)$meta['channel_id']);
                        $key = $platform . "\0" . $channelId;
                        if (isset($claimSeen[$key])) {
                            if ((int)$claimSeen[$key]['user_id'] !== (int)$claim['user_id']) {
                                throw new \RuntimeException('Canonical anti-abuse claim collision requires manual resolution: ' . $platform . '/' . $channelId);
                            }
                            $db->prepare('DELETE FROM anti_abuse_claims WHERE id = ?')->execute([(int)$claim['id']]);
                            continue;
                        }
                        $newHash = hash('sha256', 'channel' . "\0" . $key);
                        $newMeta = json_encode(['platform'=>$platform, 'channel_id'=>$channelId], JSON_UNESCAPED_UNICODE);
                        $db->prepare('UPDATE anti_abuse_claims SET identity_hash = ?, metadata = ? WHERE id = ?')
                           ->execute([$newHash, $newMeta, (int)$claim['id']]);
                        $claimSeen[$key] = $claim;
                    }
                } catch (\Throwable $e) {
                    error_log('[Postyar] v19 entitlement identity migration failed: ' . $e->getMessage());
                    throw $e;
                }
            },
            'v20_adversarial_identity_bootstrap' => function($db) {
                // Singleton row used to serialize the security-sensitive first-admin
                // decision. Existing installations with users are initialized so no
                // later registration can accidentally become superadmin.
                $driver = self::getConfig('database.driver', 'sqlite');
                if ($driver === 'mysql') {
                    $db->exec("CREATE TABLE IF NOT EXISTS system_bootstrap (id TINYINT UNSIGNED NOT NULL PRIMARY KEY, initialized_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                    $db->exec("INSERT IGNORE INTO system_bootstrap (id, initialized_at) VALUES (1, NULL)");
                    $stmt = $db->query("SELECT initialized_at FROM system_bootstrap WHERE id = 1 LIMIT 1 FOR UPDATE");
                    $row = $stmt->fetch();
                    if ($row && empty($row['initialized_at'])) {
                        $exists = $db->query("SELECT id, created_at FROM users ORDER BY id ASC LIMIT 1")->fetch();
                        if ($exists) {
                            $up = $db->prepare("UPDATE system_bootstrap SET initialized_at = ? WHERE id = 1 AND initialized_at IS NULL");
                            $up->execute([(string)$exists['created_at']]);
                        }
                    }
                } else {
                    $db->exec("CREATE TABLE IF NOT EXISTS system_bootstrap (id INTEGER PRIMARY KEY CHECK (id = 1), initialized_at DATETIME NULL)");
                    $db->exec("INSERT OR IGNORE INTO system_bootstrap (id, initialized_at) VALUES (1, NULL)");
                    $row = $db->query("SELECT initialized_at FROM system_bootstrap WHERE id = 1 LIMIT 1")->fetch();
                    if ($row && empty($row['initialized_at'])) {
                        $exists = $db->query("SELECT id, created_at FROM users ORDER BY id ASC LIMIT 1")->fetch();
                        if ($exists) {
                            $up = $db->prepare("UPDATE system_bootstrap SET initialized_at = ? WHERE id = 1 AND initialized_at IS NULL");
                            $up->execute([(string)$exists['created_at']]);
                        }
                    }
                }
            },
            'v21_performance_scale_indexes' => function($db) {
                // Wave P indexes must match the real canonical schema.  In particular,
                // clicks_log uses clicked_at (not created_at), and old MySQL installs
                // may be missing notifications because the original v12 migration used
                // SQLite-only syntax. Repair that table here before indexing it.
                $driver = self::getConfig('database.driver', 'sqlite');

                if ($driver === 'mysql') {
                    $db->exec("CREATE TABLE IF NOT EXISTS notifications (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        type VARCHAR(50) NOT NULL DEFAULT 'general',
                        title VARCHAR(255) NOT NULL,
                        message TEXT NULL,
                        target_section VARCHAR(100) NOT NULL DEFAULT '',
                        is_read TINYINT(1) NOT NULL DEFAULT 0,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_notifications_user_read(user_id, is_read),
                        INDEX idx_notifications_user_created(user_id, created_at),
                        CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                } else {
                    $db->exec("CREATE TABLE IF NOT EXISTS notifications (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id INTEGER NOT NULL,
                        type VARCHAR(50) NOT NULL DEFAULT 'general',
                        title TEXT NOT NULL,
                        message TEXT DEFAULT '',
                        target_section VARCHAR(100) DEFAULT '',
                        is_read INTEGER DEFAULT 0,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    )");
                }

                $tableExists = static function (string $table) use ($db, $driver): bool {
                    if ($driver === 'mysql') {
                        $q = $db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1");
                        $q->execute([$table]);
                        return (bool)$q->fetchColumn();
                    }
                    $q = $db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1");
                    $q->execute([$table]);
                    return (bool)$q->fetchColumn();
                };
                $columnExists = static function (string $table, string $column) use ($db, $driver): bool {
                    if ($driver === 'mysql') {
                        $q = $db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
                        $q->execute([$table, $column]);
                        return (bool)$q->fetchColumn();
                    }
                    $q = $db->prepare("PRAGMA table_info(" . str_replace('`','', $table) . ")");
                    $q->execute();
                    foreach ($q->fetchAll() as $row) if ((string)$row['name'] === $column) return true;
                    return false;
                };
                $indexExists = static function (string $table, string $index) use ($db, $driver): bool {
                    if ($driver === 'mysql') {
                        $q = $db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? LIMIT 1");
                        $q->execute([$table, $index]);
                        return (bool)$q->fetchColumn();
                    }
                    $q = $db->prepare("SELECT 1 FROM sqlite_master WHERE type='index' AND name=? LIMIT 1");
                    $q->execute([$index]);
                    return (bool)$q->fetchColumn();
                };

                $definitions = [
                    ['posts', 'idx_posts_tenant_status_created', ['tenant_id','status','created_at','id']],
                    ['posts', 'idx_posts_status_scheduled_id', ['status','scheduled_at','id']],
                    ['channel_messages', 'idx_channel_messages_post_status', ['post_id','status','channel_id']],
                    ['post_channel_stats', 'idx_post_channel_stats_channel_post', ['channel_id','post_id']],
                    // Canonical schema column is clicked_at.
                    ['clicks_log', 'idx_clicks_log_channel_post', ['channel_id','post_id','clicked_at']],
                    ['link_tracking', 'idx_link_tracking_tenant_created', ['tenant_id','created_at','id']],
                    ['link_clicks', 'idx_link_clicks_link_ip', ['link_id','ip_address']],
                    ['wallet_transactions', 'idx_wallet_transactions_user_created', ['user_id','created_at','id']],
                    ['notifications', 'idx_notifications_user_read_created', ['user_id','is_read','created_at','id']],
                    ['subscriptions', 'idx_subscriptions_user_status_end', ['user_id','status','end_date','id']],
                    ['verification_codes', 'idx_verification_user_type_active_expiry', ['user_id','type','used','expires_at','id']],
                    ['idempotency_keys', 'idx_idempotency_status_created', ['status','created_at','id']],
                ];

                foreach ($definitions as [$table, $index, $columns]) {
                    if (!$tableExists($table)) {
                        throw new \RuntimeException("Required table missing for v21: {$table}");
                    }
                    foreach ($columns as $column) {
                        if (!$columnExists($table, $column)) {
                            throw new \RuntimeException("Required column missing for v21: {$table}.{$column}");
                        }
                    }
                    if ($indexExists($table, $index)) continue;
                    $quoted = array_map(static fn($c) => '`' . str_replace('`','', $c) . '`', $columns);
                    $db->exec("CREATE INDEX `{$index}` ON `{$table}` (" . implode(',', $quoted) . ")");
                }
            },
            'v22_observability_jobs' => function($db) {
                $driver = self::getConfig('database.driver', 'sqlite');
                if ($driver === 'mysql') {
                    $db->exec("CREATE TABLE IF NOT EXISTS jobs (
                        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        type VARCHAR(80) NOT NULL, payload_json LONGTEXT NOT NULL,
                        status VARCHAR(20) NOT NULL DEFAULT 'queued', attempts INT NOT NULL DEFAULT 0,
                        max_attempts INT NOT NULL DEFAULT 5, available_at DATETIME NOT NULL,
                        worker_id VARCHAR(150) NULL, lease_until DATETIME NULL, result_json LONGTEXT NULL,
                        last_error TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_jobs_claim(status, available_at, id), INDEX idx_jobs_lease(status, lease_until, id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                } else {
                    $db->exec("CREATE TABLE IF NOT EXISTS jobs (
                        id INTEGER PRIMARY KEY AUTOINCREMENT, type VARCHAR(80) NOT NULL, payload_json TEXT NOT NULL,
                        status VARCHAR(20) NOT NULL DEFAULT 'queued', attempts INTEGER NOT NULL DEFAULT 0,
                        max_attempts INTEGER NOT NULL DEFAULT 5, available_at DATETIME NOT NULL, worker_id VARCHAR(150) NULL,
                        lease_until DATETIME NULL, result_json TEXT NULL, last_error TEXT NULL,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    )");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_jobs_claim ON jobs(status, available_at, id)");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_jobs_lease ON jobs(status, lease_until, id)");
                }
            },
            'v23_advertising' => function($db) {
                $driver = self::getConfig('database.driver', 'sqlite');
                $file = __DIR__ . '/../../migrations/' . ($driver === 'mysql' ? 'v23_advertising_mysql.sql' : 'v23_advertising.sql');
                if (!is_file($file)) { throw new \RuntimeException('Advertising migration file is missing.'); }
                $sql = file_get_contents($file);
                if ($sql === false || trim($sql) === '') { throw new \RuntimeException('Advertising migration file is empty.'); }
                $db->exec($sql);
            },
            'v24_ad_sales_workflow' => function($db) {
                $driver = self::getConfig('database.driver', 'sqlite');
                $file = __DIR__ . '/../../migrations/' . ($driver === 'mysql' ? 'v24_ad_sales_workflow_mysql.sql' : 'v24_ad_sales_workflow.sql');
                if (!is_file($file)) { throw new \RuntimeException('Ad sales migration file is missing.'); }
                $sql = file_get_contents($file);
                if ($sql === false || trim($sql) === '') { throw new \RuntimeException('Ad sales migration file is empty.'); }
                if ($driver === 'mysql') {
                    // The base MySQL schema already contains ad_campaigns. Apply only missing
                    // columns/indexes so a partially upgraded installation can safely resume.
                    $db->exec("CREATE TABLE IF NOT EXISTS ad_placements (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(80) NOT NULL UNIQUE, title VARCHAR(160) NOT NULL, description TEXT NULL, unit_price_per_day DECIMAL(12,2) NOT NULL DEFAULT 0, max_concurrent INT NOT NULL DEFAULT 10, is_active TINYINT NOT NULL DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_ad_placements_active(is_active, code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                    $db->exec("CREATE TABLE IF NOT EXISTS ad_orders (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, owner_user_id INT NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'submitted', payment_status VARCHAR(30) NOT NULL DEFAULT 'unpaid', requested_starts_at DATETIME NOT NULL, requested_ends_at DATETIME NOT NULL, quoted_amount DECIMAL(12,2) NULL, currency VARCHAR(8) NOT NULL DEFAULT 'IRR', admin_notes TEXT NULL, user_notes TEXT NULL, reviewed_by INT NULL, reviewed_at DATETIME NULL, paid_at DATETIME NULL, payment_method VARCHAR(50) NULL, payment_reference VARCHAR(120) NULL, receipt_photo TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_ad_orders_owner_status(owner_user_id,status,id), INDEX idx_ad_orders_payment(payment_status,status,id), CONSTRAINT fk_ad_orders_owner FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE, CONSTRAINT fk_ad_orders_reviewer FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                    $db->exec("CREATE TABLE IF NOT EXISTS ad_order_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL, placement_id BIGINT UNSIGNED NOT NULL, quantity INT NOT NULL DEFAULT 1, unit_price_per_day DECIMAL(12,2) NOT NULL, days INT NOT NULL, line_amount DECIMAL(12,2) NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_ad_order_items_order(order_id,id), CONSTRAINT fk_ad_order_items_order FOREIGN KEY(order_id) REFERENCES ad_orders(id) ON DELETE CASCADE, CONSTRAINT fk_ad_order_items_placement FOREIGN KEY(placement_id) REFERENCES ad_placements(id) ON DELETE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                    $db->exec("CREATE TABLE IF NOT EXISTS ad_campaign_placements (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, campaign_id BIGINT UNSIGNED NOT NULL, placement_id BIGINT UNSIGNED NOT NULL, placement_code VARCHAR(80) NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_ad_campaign_placement(campaign_id,placement_id), INDEX idx_ad_campaign_placements_code_campaign(placement_code,campaign_id), CONSTRAINT fk_ad_campaign_placements_campaign FOREIGN KEY(campaign_id) REFERENCES ad_campaigns(id) ON DELETE CASCADE, CONSTRAINT fk_ad_campaign_placements_placement FOREIGN KEY(placement_id) REFERENCES ad_placements(id) ON DELETE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                    $db->exec("CREATE TABLE IF NOT EXISTS ad_creatives (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, campaign_id BIGINT UNSIGNED NOT NULL, title VARCHAR(180) NOT NULL, body_text TEXT NULL, image_url TEXT NULL, destination_url TEXT NOT NULL, sort_order INT NOT NULL DEFAULT 0, is_active TINYINT NOT NULL DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_ad_creatives_campaign_active(campaign_id,is_active,sort_order,id), CONSTRAINT fk_ad_creatives_campaign FOREIGN KEY(campaign_id) REFERENCES ad_campaigns(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                    $columns = [
                        'order_id' => 'BIGINT UNSIGNED NULL',
                        'payment_status' => "VARCHAR(30) NOT NULL DEFAULT 'paid'",
                        'placement_code' => 'VARCHAR(80) NULL',
                        'activation_at' => 'DATETIME NULL',
                    ];
                    foreach ($columns as $column => $definition) {
                        $check = $db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ad_campaigns' AND COLUMN_NAME=? LIMIT 1");
                        $check->execute([$column]);
                        if (!$check->fetchColumn()) $db->exec("ALTER TABLE ad_campaigns ADD COLUMN {$column} {$definition}");
                    }
                    $check = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ad_campaigns' AND INDEX_NAME='idx_ad_campaigns_order' LIMIT 1");
                    if (!$check->fetchColumn()) $db->exec("CREATE INDEX idx_ad_campaigns_order ON ad_campaigns(order_id)");
                    $db->exec("INSERT IGNORE INTO ad_placements(code,title,description,unit_price_per_day,max_concurrent,is_active) VALUES ('global_top','جایگاه اصلی سراسری','اسلایدر اصلی داشبورد وب و اپلیکیشن',0,10,1),('dashboard_banner','بنر داشبورد','جایگاه بنری داخل داشبورد',0,10,1),('mobile_banner','بنر موبایل','جایگاه اختصاصی موبایل/تبلت',0,10,1)");
                } else {
                    $db->exec($sql);
                }
            },
            'v25_provider_configuration' => function($db) {
                $driver = self::getConfig('database.driver', 'sqlite');
                $file = __DIR__ . '/../../migrations/' . ($driver === 'mysql' ? 'v25_provider_configuration_mysql.sql' : 'v25_provider_configuration.sql');
                if (!is_file($file)) { throw new \RuntimeException('Provider configuration migration file is missing.'); }
                $sql = file_get_contents($file);
                if ($sql === false || trim($sql) === '') { throw new \RuntimeException('Provider configuration migration file is empty.'); }
                $db->exec($sql);
            },
            'v26_payment_settlement' => function($db) {
                $driver = self::getConfig('database.driver', 'sqlite');
                $file = __DIR__ . '/../../migrations/' . ($driver === 'mysql' ? 'v26_payment_settlement_mysql.sql' : 'v26_payment_settlement.sql');
                if (!is_file($file)) { throw new \RuntimeException('Payment settlement migration file is missing.'); }
                $sql = file_get_contents($file);
                if ($sql === false || trim($sql) === '') { throw new \RuntimeException('Payment settlement migration file is empty.'); }
                $db->exec($sql);
            },
            'v27_scale_concurrency_hardening' => function($db) {
                // Lease fencing prevents a stale worker from completing/failing a job
                // after its lease has expired and another worker has claimed it.
                try { $db->exec("ALTER TABLE jobs ADD COLUMN lease_token VARCHAR(64) NULL"); } catch (\Throwable $e) {}
                try { $db->exec("CREATE INDEX idx_jobs_worker_lease ON jobs(worker_id, lease_token, status)"); } catch (\Throwable $e) {}
                try { $db->exec("CREATE INDEX idx_rate_limits_last_attempt ON rate_limits(last_attempt)"); } catch (\Throwable $e) {}
            },
            'v29_secret_and_private_storage_hardening' => function($db) {
                $secretKey = (string)(getenv('POSTYAR_SECRET_KEY') ?: self::getConfig('security.secret_key', ''));
                if ($secretKey === '' || $secretKey === '********************************************') {
                    throw new \RuntimeException('POSTYAR_SECRET_KEY must be configured before v29 secret migration.');
                }

                // Encrypt legacy plaintext channel tokens.
                $rows = $db->query("SELECT id, token FROM channels")->fetchAll();
                foreach ($rows as $row) {
                    $token = (string)($row['token'] ?? '');
                    if ($token === '' || str_starts_with($token, 'enc:v1:')) continue;
                    $encrypted = \WHCM\Core\SecretStore::encrypt($token);
                    $stmt = $db->prepare("UPDATE channels SET token = ? WHERE id = ?");
                    $stmt->execute([$encrypted, (int)$row['id']]);
                }

                // Encrypt legacy plaintext AI API keys.
                $stmt = $db->query("SELECT tenant_id, key_value FROM settings WHERE key_name = 'ai_api_key'");
                foreach ($stmt->fetchAll() as $row) {
                    $value = (string)($row['key_value'] ?? '');
                    if ($value === '' || str_starts_with($value, 'enc:v1:')) continue;
                    $encrypted = \WHCM\Core\SecretStore::encrypt($value);
                    $up = $db->prepare("UPDATE settings SET key_value = ? WHERE tenant_id = ? AND key_name = 'ai_api_key'");
                    $up->execute([$encrypted, (int)$row['tenant_id']]);
                }

                // Migrate legacy public ticket attachments into private storage.
                $public = rtrim((string)self::getConfig('paths.public_assets_path', __DIR__ . '/../../public/assets'), '/\\') . '/tickets/';
                $private = rtrim((string)self::getConfig('paths.private_storage_path', __DIR__ . '/../../storage/private'), '/\\') . '/tickets/';
                if (!is_dir($private) && !mkdir($private, 0750, true) && !is_dir($private)) {
                    throw new \RuntimeException('Unable to create private ticket storage.');
                }
                if (is_dir($public)) {
                    $stmt = $db->query("SELECT id, user_id, attachment, message FROM tickets WHERE attachment IS NOT NULL AND attachment <> ''");
                    foreach ($stmt->fetchAll() as $ticket) {
                        $attachment = (string)$ticket['attachment'];
                        $name = basename(parse_url($attachment, PHP_URL_PATH) ?: $attachment);
                        if ($name === '' || !preg_match('/^ticket_[A-Za-z0-9_-]+\\.(?:jpg|jpeg|png|gif|webp|pdf)$/i', $name)) continue;
                        $src = $public . $name;
                        $dst = $private . $name;
                        if (is_file($src)) {
                            if (!is_file($dst) && !rename($src, $dst)) {
                                throw new \RuntimeException('Unable to migrate ticket attachment ' . $name);
                            }
                            $newRef = 'private://tickets/' . $name;
                            $up = $db->prepare("UPDATE tickets SET attachment = ?, message = REPLACE(REPLACE(message, ?, ?), ?, ?) WHERE id = ?");
                            $old1 = '/assets/tickets/' . $name;
                            $old2 = (string)$attachment;
                            $up->execute([$newRef, $old1, $newRef, $old2, $newRef, (int)$ticket['id']]);
                        }
                    }
                }
            },
            'v28_legacy_schema_repair' => function($db) {
                // Repair legacy upgraded MySQL/SQLite installations where an earlier
                // migration was marked complete after swallowing a schema error.
                $driver = self::getConfig('database.driver', 'sqlite');
                $file = __DIR__ . '/../../migrations/' . ($driver === 'mysql' ? 'v28_legacy_schema_repair_mysql.sql' : 'v28_legacy_schema_repair.sql');
                if (!is_file($file)) { throw new \RuntimeException('Legacy schema repair migration file is missing.'); }
                $sql = file_get_contents($file);
                if ($sql === false || trim($sql) === '') { throw new \RuntimeException('Legacy schema repair migration file is empty.'); }
                $db->exec($sql);
            },
        ];

        foreach ($migrations as $version => $callback) {
            if (!self::hasMigrationRun($version)) {
                try {
                    error_log('[Postyar Migration] START ' . $version);
                    $callback(self::$db);
                    self::setMigrationVersion($version);
                    error_log('[Postyar Migration] OK ' . $version);
                } catch (\Throwable $e) {
                    // Never expose schema/credential details to the browser, but always leave
                    // an actionable server-side trail on cPanel/LiteSpeed.
                    error_log('[Postyar Migration] FAILED ' . $version . ' | ' . get_class($e) . ' | ' . $e->getMessage());
                    throw $e;
                }
            }
        }
    }

    /**
     * بررسی اینکه آیا مایگریشن خاصی قبلاً اجرا شده یا خیر
     */
    private static function hasMigrationRun(string $version): bool {
        try {
            $db = self::$db;
            // بررسی وجود جدول schema_migrations
            if (self::getConfig('database.driver') === 'sqlite') {
                $stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='schema_migrations'");
            } else {
                $stmt = $db->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations'");
            }
            if (!$stmt->fetch()) {
                return false;
            }

            $stmt = $db->prepare("SELECT 1 FROM schema_migrations WHERE version = ? LIMIT 1");
            $stmt->execute([$version]);
            return (bool)$stmt->fetch();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * ثبت نسخه مایگریشن به عنوان اجرا شده
     */
    private static function setMigrationVersion(string $version): void {
        $db = self::$db;
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(100) PRIMARY KEY, executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
            if (self::getConfig('database.driver', 'sqlite') === 'mysql') {
                $stmt = $db->prepare("INSERT IGNORE INTO schema_migrations (version) VALUES (?)");
            } else {
                $stmt = $db->prepare("INSERT OR IGNORE INTO schema_migrations (version) VALUES (?)");
            }
            $stmt->execute([$version]);
        } catch (\Throwable $e) {
            error_log('[Postyar] migration bookkeeping failed for ' . $version . ': ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * دریافت آدرس هوشمند و کاملاً پویا برای دارایی‌های عمومی (Assets)
     */
    public static function getAssetsUrl() {
        $configuredUrl = trim((string) self::getConfig('paths.public_assets_url', ''));
        if ($configuredUrl !== '') {
            return rtrim((string) self::getConfig('app.url', ''), '/') . '/' . ltrim($configuredUrl, '/');
        }
        $app_url = rtrim((string) self::getConfig('app.url', ''), '/');
        return $app_url . '/assets';
    }

    /**
     * تولید آدرس پایدار با ساختار پارامتری
     */
    public static function getRouteUrl(string $path) {
        $app_url = rtrim(self::getConfig('app.url'), '/');
        $parts = explode('?', ltrim($path, '/'), 2);
        $route = '/' . $parts[0];
        $query = isset($parts[1]) ? '&' . $parts[1] : '';
        return $app_url . '/index.php?route=' . urlencode($route) . $query;
    }

    /**
     * تولید آدرس تصویر پلن اشتراک
     */
    public static function getPlanImageUrl($url) {
        if (empty($url)) {
            return '';
        }
        if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
            $parts = explode('/assets/', $url);
            if (count($parts) > 1) {
                return self::getAssetsUrl() . '/' . $parts[1];
            }
            return $url;
        }

        $parts = explode('/assets/', $url);
        if (count($parts) > 1) {
            return self::getAssetsUrl() . '/' . $parts[1];
        }

        return self::getAssetsUrl() . '/' . ltrim($url, '/');
    }

    /**
     * پارسر هوشمند SQL: تقسیم کوئری‌ها با در نظر گرفتن commentها و stringها
     */
    private static function splitSqlQueries(string $sql): array {
        $queries = [];
        $current = '';
        $in_string = false;
        $string_char = '';
        $in_line_comment = false;
        $in_block_comment = false;
        $len = strlen($sql);

        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];
            $next = ($i + 1 < $len) ? $sql[$i + 1] : '';
            $prev = ($i > 0) ? $sql[$i - 1] : '';

            if (!$in_line_comment && !$in_block_comment) {
                if (!$in_string && ($char === "'" || $char === '"') ) {
                    $in_string = true;
                    $string_char = $char;
                } elseif ($in_string && $char === $string_char && $prev !== '\\') {
                    $in_string = false;
                    $string_char = '';
                }
            }

            if (!$in_string && !$in_block_comment && $char === '-' && $next === '-') {
                $in_line_comment = true;
            }
            if ($in_line_comment && $char === "\n") {
                $in_line_comment = false;
            }

            if (!$in_string && !$in_line_comment && $char === '/' && $next === '*') {
                $in_block_comment = true;
            }
            if ($in_block_comment && $prev === '*' && $char === '/') {
                $in_block_comment = false;
            }

            if ($char === ';' && !$in_string && !$in_line_comment && !$in_block_comment) {
                $queries[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $queries[] = $current;
        }

        return $queries;
    }

    /**
     * پاکسازی دیسک — فقط از طریق Cron Job فراخوانی شود
     * تصاویر قدیمی‌تر از N روز حذف می‌شوند.
     */
    public static function cleanupOldUploads(int $days = 30): int {
        $count = 0;
        $uploads_dir = rtrim((string) self::getConfig('paths.public_assets_path', __DIR__ . '/../../public/assets'), '/\\') . '/uploads/';
        $now = time();
        $max_age = $days * 86400;

        if (!file_exists($uploads_dir)) {
            return 0;
        }

        $files = glob($uploads_dir . '*.{webp,jpg,jpeg,png,gif}', GLOB_BRACE);
        foreach ($files as $file) {
            if (is_file($file) && ($now - filemtime($file)) > $max_age) {
                unlink($file);
                $count++;
            }
        }

        return $count;
    }
}
