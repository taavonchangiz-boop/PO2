<?php
namespace WHCM\Core;

use WHCM\Domain\AntiAbuse;

/**
 * مدیریت احراز هویت و دسترسی‌های کاربران پلتفرم
 *
 * @package WHCM\Core
 */
class Auth {
    /** @var array|null */
    private static $currentUser = null;

    /**
     * ثبت نام کاربر جدید با تخصیص پیش‌فرض پلن رایگان
     *
     * @param string $name نام و نام خانوادگی
     * @param string $email ایمیل
     * @param string $password رمز عبور خام
     * @return array ['success' => bool, 'message' => string, 'user_id' => int|null]
     */
    public static function register(string $name, string $email, string $password, string $business_name = '', string $business_type = '', string $phone = ''): array {
        $db = Bootstrap::getDB();
        $phone = AntiAbuse::normalizePhone($phone);
        if (!AntiAbuse::validPhone($phone)) {
            return ['success' => false, 'message' => 'شماره موبایل معتبر و اجباری است.'];
        }

        // شماره موبایل باید برای هر حساب یکتا باشد.
        $phoneStmt = $db->prepare('SELECT id FROM users WHERE phone = ? LIMIT 2');
        $phoneStmt->execute([$phone]);
        if ($phoneStmt->fetch()) {
            return ['success' => false, 'message' => 'این شماره موبایل قبلاً در سامانه ثبت شده است.'];
        }

        // بررسی یکتایی ایمیل
        $stmt = $db->prepare("SELECT id, role, status, password FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $existing = $stmt->fetch();
        if ($existing) {
            return [
                'success' => false,
                'message' => 'کاربری با این نشانی ایمیل قبلاً در سامانه ثبت‌نام کرده است.'
            ];
        }

        // هش کردن امن رمز عبور
        $hashed_password = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $db->beginTransaction();
        try {
            // Claim باید قبل از INSERT کاربر رزرو شود تا دو ثبت‌نام همزمان با یک شماره
            // نتوانند هر دو حساب بسازند. در صورت rollback، claim نیز rollback می‌شود.
            $freeTrialClaimed = AntiAbuse::claimFreeTrial($db, 0, $phone);
            if (!$freeTrialClaimed) {
                throw new \RuntimeException('این شماره موبایل قبلاً سهمیه آزمایشی رایگان خود را مصرف کرده است.');
            }

            // ۱. تعیین نقش اولین مدیر با قفل DB، نه SELECT COUNT ساده.
            // SELECT COUNT در MySQL زیر بار همزمان می‌تواند دو ثبت‌نام را همزمان
            // «اولین کاربر» ببیند و دو superadmin بسازد. ردیف singleton زیر برای این
            // تصمیم امنیتی قفل می‌شود و فقط یک تراکنش می‌تواند bootstrap را انجام دهد.
            $driver = Bootstrap::getConfig('database.driver', 'sqlite');
            if ($driver === 'sqlite') {
                $db->exec("INSERT OR IGNORE INTO system_bootstrap (id, initialized_at) VALUES (1, NULL)");
                $bootstrapStmt = $db->prepare('SELECT initialized_at FROM system_bootstrap WHERE id = 1 LIMIT 1');
                $bootstrapStmt->execute();
            } else {
                $db->exec("INSERT IGNORE INTO system_bootstrap (id, initialized_at) VALUES (1, NULL)");
                $bootstrapStmt = $db->prepare('SELECT initialized_at FROM system_bootstrap WHERE id = 1 LIMIT 1 FOR UPDATE');
                $bootstrapStmt->execute();
            }
            $bootstrapRow = $bootstrapStmt->fetch();
            if (!$bootstrapRow) throw new \RuntimeException('قفل اولیه سامانه در دسترس نیست.');
            $role = empty($bootstrapRow['initialized_at']) ? 'superadmin' : 'user';

            $stmt = $db->prepare("INSERT INTO users (name, email, password, role, status, business_name, business_type, phone) VALUES (?, ?, ?, ?, 'active', ?, ?, ?)");
            $stmt->execute([$name, $email, $hashed_password, $role, $business_name, $business_type, $phone]);
            $user_id = (int)$db->lastInsertId();

            // Claim اکنون به کاربر تازه‌ساخته‌شده متصل می‌شود.
            $stmt = $db->prepare('UPDATE anti_abuse_claims SET user_id = ? WHERE claim_type = ? AND identity_hash = ?');
            $stmt->execute([$user_id, 'free_trial_phone', hash('sha256', 'free_trial_phone' . "\0" . $phone)]);

            // Mark bootstrap initialization only after the user INSERT succeeded.
            // If the transaction rolls back, the marker remains NULL and the next
            // successful registration can become the single initial superadmin.
            $db->prepare('UPDATE system_bootstrap SET initialized_at = ? WHERE id = 1 AND initialized_at IS NULL')
                ->execute([date('Y-m-d H:i:s')]);

            $free_plan = null;
            if ($freeTrialClaimed) {
                $stmt = $db->prepare("SELECT id, duration_days FROM plans WHERE price = 0 LIMIT 1");
                $stmt->execute();
                $free_plan = $stmt->fetch();
            }

            if ($freeTrialClaimed && !$free_plan) {
                // ساخت پلن رایگان پیش‌فرض طبق خواسته‌ی کاربر (۱ تلگرام، ۱ بله، حداکثر ۱۰ پست)
                $features = json_encode([
                    'gold_ticker' => false,
                    'auto_responder' => false,
                    'woocommerce' => false,
                    'stats' => true
                ], JSON_UNESCAPED_UNICODE);

                $stmt = $db->prepare("INSERT INTO plans (title, price, duration_days, max_channels, max_posts, features) VALUES ('پلن آزمایشی رایگان', 0, 0, 2, 10, ?)");
                $stmt->execute([$features]);
                $plan_id = (int)$db->lastInsertId();
                $duration_days = 0; // بدون انقضا (محدودیت تعداد کل پست = ۱۰)
            } elseif ($free_plan) {
                $plan_id = (int)$free_plan['id'];
                $duration_days = (int)$free_plan['duration_days'];
            } else {
                $plan_id = 0;
                $duration_days = 0;
            }

            // ۳. انتساب اشتراک رایگان فقط در صورت موفقیت Claim
            if ($freeTrialClaimed && $free_plan) {
                $start_date = date('Y-m-d H:i:s');
                $end_date = $duration_days > 0
                    ? date('Y-m-d H:i:s', strtotime("+{$duration_days} days"))
                    : '2099-12-30 00:00:00';

                $stmt = $db->prepare("INSERT INTO subscriptions (user_id, plan_id, start_date, end_date, status) VALUES (?, ?, ?, ?, 'active')");
                $stmt->execute([$user_id, $plan_id, $start_date, $end_date]);
            }

            $db->commit();
            return [
                'success' => true,
                'message' => 'ثبت‌نام با موفقیت انجام شد. اکنون می‌توانید وارد حساب خود شوید.',
                'user_id' => $user_id
            ];

        } catch (\Exception $e) {
            $db->rollBack();
            return [
                'success' => false,
                'message' => 'خطایی رخ داد: ' . $e->getMessage()
            ];
        }
    }

    /**
     * ورود کاربر به سامانه
     *
     * @param string $email ایمیل
     * @param string $password رمز عبور خام
     * @return array ['success' => bool, 'message' => string]
     */
    public static function login(string $email, string $password): array {
        $db = Bootstrap::getDB();

        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            return [
                'success' => false,
                'message' => 'مشخصات ورود نامعتبر است.'
            ];
        }

        if ($user['status'] !== 'active') {
            return [
                'success' => false,
                'message' => 'حساب کاربری شما معلق یا غیرفعال شده است. با پشتیبانی تماس بگیرید.'
            ];
        }

        // بررسی رمز عبور با متد ایمن و مدرن
        if (password_verify($password, $user['password'])) {
            // بازنشانی شناسه سشن برای امنیت بیشتر
            Session::regenerate();
            Csrf::rotate();
            Session::set('user_id', (int)$user['id']);

            self::$currentUser = $user;

            return [
                'success' => true,
                'message' => 'ورود با موفقیت انجام شد.'
            ];
        }

        return [
            'success' => false,
            'message' => 'مشخصات ورود نامعتبر است.'
        ];
    }

    /**
     * ورود امن بر اساس شناسه کاربر؛ برای OTP و بازیابی حساب استفاده می‌شود.
     */
    public static function loginByUserId(int $userId): bool {
        $db = Bootstrap::getDB();
        $stmt = $db->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user || $user['status'] !== 'active') return false;
        Session::regenerate();
        Csrf::rotate();
        Session::set('user_id', $userId);
        self::$currentUser = $user;
        return true;
    }

    /**
     * ورود با ایمیل/رمز و در صورت ارائه شماره، تطبیق شماره ثبت‌شده.
     */
    public static function loginWithPhoneBinding(string $email, string $password, string $phone = ''): array {
        $db = Bootstrap::getDB();
        $stmt = $db->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([trim($email)]);
        $user = $stmt->fetch();
        if (!$user || $user['status'] !== 'active' || !password_verify($password, $user['password'])) {
            return ['success' => false, 'message' => 'مشخصات ورود نامعتبر است.'];
        }
        if ($phone !== '') {
            $normalized = \WHCM\Domain\AntiAbuse::normalizePhone($phone);
            if (!$normalized || !hash_equals((string)($user['phone'] ?? ''), $normalized)) {
                return ['success' => false, 'message' => 'مشخصات ورود نامعتبر است.'];
            }
        }
        self::loginByUserId((int)$user['id']);
        return ['success' => true, 'message' => 'ورود با موفقیت انجام شد.'];
    }

    /**
     * خروج کامل کاربر
     */
    public static function logout() {
        Session::destroy();
        self::$currentUser = null;
    }

    /**
     * بازیابی مشخصات کاربر فعلی
     */
    public static function user() {
        if (self::$currentUser !== null) {
            return self::$currentUser;
        }

        $user_id = Session::get('user_id');
        if (!$user_id) {
            return null;
        }

        $db = Bootstrap::getDB();
        $stmt = $db->prepare("SELECT id, name, email, role, status, birthday, created_at FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();

        if ($user && $user['status'] === 'active') {
            self::$currentUser = $user;
            return self::$currentUser;
        }

        // اگر کاربر معلق شده باشد، سشن منقضی می‌شود
        self::logout();
        return null;
    }

    /**
     * بررسی ورود کاربر
     */
    public static function check(): bool {
        return self::user() !== null;
    }

    /**
     * بررسی اینکه آیا کاربر مدیر کل است
     */
    public static function isSuperAdmin(): bool {
        $user = self::user();
        return $user && $user['role'] === 'superadmin';
    }

    /**
     * بررسی اینکه آیا کاربر پشتیبان است
     */
    public static function isSupportAgent(): bool {
        $user = self::user();
        return $user && $user['role'] === 'support_agent';
    }

    /**
     * بررسی اینکه آیا کاربر مدیر کل یا پشتیبان است
     */
    public static function isAdminOrSupport(): bool {
        return self::isSuperAdmin() || self::isSupportAgent();
    }

    /**
     * شناسه مستاجر جاری (Tenant ID) برای فیلتر دیتابیس
     */
    public static function tenantId() {
        $user = self::user();
        return $user ? (int)$user['id'] : null;
    }
}
