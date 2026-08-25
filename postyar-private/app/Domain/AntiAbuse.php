<?php
namespace WHCM\Domain;

use WHCM\Core\Bootstrap;

/**
 * Immutable anti-abuse claims.
 * Claims are intentionally never deleted when a user is deleted.
 * This prevents re-use of a previously consumed free-trial phone or channel ID.
 */
class AntiAbuse
{
    public static function normalizePhone(string $phone): string
    {
        $phone = strtr(trim($phone), [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ]);
        $phone = preg_replace('/[^0-9+]/', '', $phone) ?? '';
        if (str_starts_with($phone, '+98')) {
            $phone = '0' . substr($phone, 3);
        } elseif (str_starts_with($phone, '0098')) {
            $phone = '0' . substr($phone, 4);
        } elseif (str_starts_with($phone, '98') && strlen($phone) === 12) {
            $phone = '0' . substr($phone, 2);
        }
        return $phone;
    }

    public static function validPhone(string $phone): bool
    {
        return (bool)preg_match('/^09[0-9]{9}$/', self::normalizePhone($phone));
    }

    public static function normalizePlatform(string $platform): string
    {
        return strtolower(trim($platform));
    }

    /** Canonical channel identity; prevents @foo vs foo and case variants from bypassing a claim. */
    public static function normalizeChannelId(string $channelId): string
    {
        $channelId = trim($channelId);
        if (str_starts_with($channelId, '@')) $channelId = substr($channelId, 1);
        return strtolower(trim($channelId));
    }

    private static function hash(string $type, string $identity): string
    {
        return hash('sha256', $type . "\0" . $identity);
    }

    /** Atomically reserves a free-trial identity. */
    public static function claimFreeTrial(\PDO $db, int $userId, string $phone): bool
    {
        $normalized = self::normalizePhone($phone);
        $hash = self::hash('free_trial_phone', $normalized);
        try {
            $stmt = $db->prepare(
                'INSERT INTO anti_abuse_claims (claim_type, identity_hash, user_id, created_at) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute(['free_trial_phone', $hash, $userId, date('Y-m-d H:i:s')]);
            return true;
        } catch (\PDOException $e) {
            // UNIQUE(claim_type, identity_hash) is the concurrency gate.
            if ((string)$e->getCode() === '23000' || stripos($e->getMessage(), 'unique') !== false) {
                return false;
            }
            throw $e;
        }
    }

    /** Atomically reserves a platform/channel identifier forever. */
    public static function claimChannel(\PDO $db, int $userId, string $platform, string $channelId): bool
    {
        $platform = self::normalizePlatform($platform);
        $channelId = self::normalizeChannelId($channelId);
        $identity = $platform . "\0" . $channelId;
        $hash = self::hash('channel', $identity);
        try {
            $stmt = $db->prepare(
                'INSERT INTO anti_abuse_claims (claim_type, identity_hash, user_id, metadata, created_at) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute(['channel', $hash, $userId, json_encode(['platform'=>$platform,'channel_id'=>$channelId], JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s')]);
            return true;
        } catch (\PDOException $e) {
            if ((string)$e->getCode() === '23000' || stripos($e->getMessage(), 'unique') !== false) {
                return false;
            }
            throw $e;
        }
    }

    public static function claimOwner(\PDO $db, string $type, string $identity): ?int
    {
        $hash = self::hash($type, $identity);
        $stmt = $db->prepare('SELECT user_id FROM anti_abuse_claims WHERE claim_type = ? AND identity_hash = ? LIMIT 1');
        $stmt->execute([$type, $hash]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (int)$value;
    }

    public static function hasClaim(\PDO $db, string $type, string $identity): bool
    {
        $hash = self::hash($type, $identity);
        $stmt = $db->prepare('SELECT 1 FROM anti_abuse_claims WHERE claim_type = ? AND identity_hash = ? LIMIT 1');
        $stmt->execute([$type, $hash]);
        return (bool)$stmt->fetchColumn();
    }
}
