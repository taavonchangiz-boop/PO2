<?php
namespace WHCM\Api;

use WHCM\Core\Bootstrap;
use WHCM\Core\RateLimit;

/**
 * سیستم احراز هویت API موبایل (Token-Based)
 *
 * مسئول تولید، اعتبارسنجی و مدیریت توکن‌های API.
 *
 * @package WHCM\Api
 */
class MobileApiAuth {

    /**
     * دریافت هدر Authorization و استخراج توکن
     */
    public static function getTokenFromRequest(): ?string {

        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (empty($header)) {
            return null;
        }

        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }


    /**
     * تولید توکن تصادفی امن
     */
    public static function generateToken(): string {

        return bin2hex(random_bytes(32));
    }


    /**
     * هش کردن توکن
     */
    public static function hashToken(string $token): string {

        return hash('sha256', $token);
    }


    /**
     * ورود کاربر و تولید توکن API
     */
    public static function authenticate(
        string $email,
        string $password,
        string $deviceName = 'android'
    ): array {


        if (!RateLimit::consume('api_login', 10, 300, strtolower(trim($email)))) {

            return [
                'success' => false,
                'token' => null,
                'user' => null,
                'message' => 'تعداد تلاش‌های ناموفق بیش از حد مجاز است. ۵ دقیقه صبر کنید.'
            ];
        }


        $db = Bootstrap::getDB();


        $stmt = $db->prepare(
            "SELECT * FROM users WHERE email = ? LIMIT 1"
        );

        $stmt->execute([$email]);

        $user = $stmt->fetch();


        if (!$user) {

            return [
                'success' => false,
                'token' => null,
                'user' => null,
                'message' => 'ایمیل یا کلمه عبور نادرست است.'
            ];
        }


        if ($user['status'] !== 'active') {

            return [
                'success' => false,
                'token' => null,
                'user' => null,
                'message' => 'حساب کاربری شما غیرفعال یا معلق شده است. با پشتیبانی تماس بگیرید.'
            ];
        }


        if (!password_verify($password, $user['password'])) {

            return [
                'success' => false,
                'token' => null,
                'user' => null,
                'message' => 'ایمیل یا کلمه عبور نادرست است.'
            ];
        }


        /*
         * تولید توکن جدید
         * سازگار با MySQL و SQLite
         */
        $token = self::generateToken();

        $tokenHash = self::hashToken($token);

        $now = date('Y-m-d H:i:s');

        $expiresAt = date(
            'Y-m-d H:i:s',
            strtotime('+30 days')
        );


        $stmt = $db->prepare(
            "
            INSERT INTO api_tokens
            (
                user_id,
                token_hash,
                device_name,
                created_at,
                last_used_at,
                expires_at
            )
            VALUES (?, ?, ?, ?, ?, ?)
            "
        );


        $stmt->execute([
            $user['id'],
            $tokenHash,
            $deviceName,
            $now,
            $now,
            $expiresAt
        ]);



        RateLimit::clear('api_login', strtolower(trim($email)));



        /*
         * حذف توکن‌های قدیمی
         * اصلاح شده برای MySQL Error 1093
         */
        $stmt = $db->prepare(
            "
            DELETE FROM api_tokens
            WHERE user_id = ?
            AND id NOT IN
            (
                SELECT id FROM
                (
                    SELECT id
                    FROM api_tokens
                    WHERE user_id = ?
                    ORDER BY created_at DESC
                    LIMIT 5
                ) AS tmp_tokens
            )
            "
        );


        $stmt->execute([
            $user['id'],
            $user['id']
        ]);



        return [
            'success' => true,
            'token' => $token,
            'user' => self::sanitizeUser($user),
            'message' => 'ورود موفقیت‌آمیز بود.'
        ];
    }



    public static function issueTokenForUser(int $userId, string $deviceName = 'android'): ?string {
        $db = Bootstrap::getDB();
        $stmt = $db->prepare("SELECT id, status FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user || $user['status'] !== 'active') return null;
        $token = self::generateToken();
        $now = date('Y-m-d H:i:s');
        $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
        $stmt = $db->prepare('INSERT INTO api_tokens (user_id, token_hash, device_name, created_at, last_used_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$userId, self::hashToken($token), substr($deviceName, 0, 100), $now, $now, $expires]);
        return $token;
    }

    public static function sanitizeUserById(int $userId): array {
        $db = Bootstrap::getDB();
        $stmt = $db->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        return self::sanitizeUser($stmt->fetch() ?: []);
    }

    /**
     * اعتبارسنجی توکن
     */
    public static function validate(): ?array {


        $token = self::getTokenFromRequest();


        if (empty($token)) {
            return null;
        }


        $tokenHash = self::hashToken($token);


        $db = Bootstrap::getDB();


        $now = date('Y-m-d H:i:s');



        $stmt = $db->prepare(
            "
            SELECT t.*, u.*
            FROM api_tokens t
            JOIN users u
            ON t.user_id = u.id
            WHERE t.token_hash = ?
            AND t.revoked_at IS NULL
            AND t.expires_at > ?
            LIMIT 1
            "
        );


        $stmt->execute([
            $tokenHash,
            $now
        ]);


        $row = $stmt->fetch();



        if (!$row) {
            return null;
        }



        if ($row['status'] !== 'active') {
            return null;
        }



        $stmt = $db->prepare(
            "
            UPDATE api_tokens
            SET last_used_at = ?
            WHERE id = ?
            "
        );


        $stmt->execute([
            $now,
            $row['id']
        ]);



        return self::sanitizeUser($row);
    }



    /**
     * ابطال توکن فعلی
     */
    public static function revokeCurrentToken(): bool {


        $token = self::getTokenFromRequest();


        if (empty($token)) {
            return false;
        }



        $tokenHash = self::hashToken($token);


        $db = Bootstrap::getDB();


        $now = date('Y-m-d H:i:s');



        $stmt = $db->prepare(
            "
            UPDATE api_tokens
            SET revoked_at = ?
            WHERE token_hash = ?
            "
        );


        $stmt->execute([
            $now,
            $tokenHash
        ]);



        return $stmt->rowCount() > 0;
    }



    /**
     * ابطال همه توکن‌های کاربر
     */
    public static function revokeAllUserTokens(int $userId): int {


        $db = Bootstrap::getDB();


        $now = date('Y-m-d H:i:s');



        $stmt = $db->prepare(
            "
            UPDATE api_tokens
            SET revoked_at = ?
            WHERE user_id = ?
            AND revoked_at IS NULL
            "
        );


        $stmt->execute([
            $now,
            $userId
        ]);



        return $stmt->rowCount();
    }



    /**
     * حذف اطلاعات حساس
     */
    public static function sanitizeUser(array $user): array {


        return [
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'status' => $user['status'],
            'business_name' => $user['business_name'] ?? null,
            'business_type' => $user['business_type'] ?? null,
            'phone' => $user['phone'] ?? null,
            'birthday' => $user['birthday'] ?? null,
            'referral_code' => $user['referral_code'] ?? null,
            'referral_points' => (float)($user['referral_points'] ?? 0),
            'wallet_balance' => (float)($user['wallet_balance'] ?? 0),
            'created_at' => $user['created_at']
        ];
    }



    /**
     * تزریق user_id به session
     */
    public static function injectSession(int $userId): void {

        $_SESSION['user_id'] = $userId;
    }



    /**
     * پاکسازی session
     */
    public static function clearInjectedSession(): void {

        unset($_SESSION['user_id']);
    }
}