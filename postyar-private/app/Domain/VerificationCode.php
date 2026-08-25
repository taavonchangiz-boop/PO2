<?php
namespace WHCM\Domain;

use WHCM\Core\Bootstrap;

class VerificationCode {
    public static function generate(int $userId, string $type, int $expiryMinutes = 5): string {
        $db = Bootstrap::getDB();
        $code = (string)random_int(100000, 999999);
        $expiresAt = date('Y-m-d H:i:s', time() + ($expiryMinutes * 60));
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('DELETE FROM verification_codes WHERE user_id = ? AND type = ? AND used = 0');
            $stmt->execute([$userId, $type]);
            $stmt = $db->prepare('INSERT INTO verification_codes (user_id, type, code, expires_at) VALUES (?, ?, ?, ?)');
            $stmt->execute([$userId, $type, $code, $expiresAt]);
            $db->commit();
            return $code;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function verify(int $userId, string $type, string $code): bool {
        $record = self::findActive($userId, $type, $code);
        return $record ? self::consume((int)$record['id']) : false;
    }

    /** Atomic one-time consumption. Safe against two concurrent verifiers. */
    public static function consume(int $id): bool {
        $db = Bootstrap::getDB();
        $now = date('Y-m-d H:i:s');
        $stmt = $db->prepare("UPDATE verification_codes SET used = 1 WHERE id = ? AND used = 0 AND expires_at >= ?");
        $stmt->execute([$id, $now]);
        return $stmt->rowCount() === 1;
    }

    public static function findActive(int $userId, string $type, string $code): ?array {
        $db = Bootstrap::getDB();
        $stmt = $db->prepare('SELECT * FROM verification_codes WHERE user_id = ? AND type = ? AND used = 0 ORDER BY created_at DESC LIMIT 1');
        $stmt->execute([$userId, $type]);
        $record = $stmt->fetch();
        if (!$record || !hash_equals((string)$record['code'], (string)$code) || strtotime($record['expires_at']) < time()) return null;
        return $record;
    }

    public static function findActiveByUserAndCode(int $userId, string $type, string $code): ?array {
        return self::findActive($userId, $type, $code);
    }

    public static function cleanup(): void {
        $db = Bootstrap::getDB();
        $driver = Bootstrap::getConfig('database.driver', 'sqlite');
        try {
            if ($driver === 'mysql') $db->prepare("DELETE FROM verification_codes WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)")->execute();
            else $db->prepare("DELETE FROM verification_codes WHERE created_at < datetime('now', '-24 hours')")->execute();
        } catch (\Throwable $e) {}
    }
}
