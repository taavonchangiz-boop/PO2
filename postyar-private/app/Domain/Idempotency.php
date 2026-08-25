<?php
namespace WHCM\Domain;

use WHCM\Core\Bootstrap;

/** Small DB-backed idempotency primitive for retry-safe API operations. */
class Idempotency
{
    public static function normalizeKey(?string $key): ?string
    {
        $key = trim((string)$key);
        if ($key === '' || strlen($key) > 128) return null;
        return preg_match('/^[A-Za-z0-9._:-]+$/', $key) ? $key : null;
    }

    public static function existing(\PDO $db, int $userId, string $operation, string $key): ?array
    {
        $stmt = $db->prepare('SELECT * FROM idempotency_keys WHERE user_id = ? AND operation = ? AND idem_key = ? LIMIT 1');
        $stmt->execute([$userId, $operation, $key]);
        $row = $stmt->fetch();
        if (!$row) return null;

        // Crash recovery: a worker that died after reservation must not block a client forever.
        // Never reclaim fresh processing keys; only stale reservations are released.
        if (($row['status'] ?? '') === 'processing') {
            $cutoff = date('Y-m-d H:i:s', time() - 1800); // 30 minutes
            if ((string)($row['created_at'] ?? '') < $cutoff) {
                $recover = $db->prepare("UPDATE idempotency_keys SET status = 'failed' WHERE user_id = ? AND operation = ? AND idem_key = ? AND status = 'processing' AND created_at < ?");
                $recover->execute([$userId, $operation, $key, $cutoff]);
                if ($recover->rowCount() === 1) {
                    $row['status'] = 'failed';
                }
            }
        }
        return $row;
    }

    /** Reserve a key. Returns false when another request already owns it. */
    public static function reserve(\PDO $db, int $userId, string $operation, string $key): bool
    {
        try {
            $stmt = $db->prepare('INSERT INTO idempotency_keys (user_id, operation, idem_key, status, created_at) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$userId, $operation, $key, 'processing', date('Y-m-d H:i:s')]);
            return true;
        } catch (\PDOException $e) {
            if ((string)$e->getCode() === '23000' || stripos($e->getMessage(), 'unique') !== false) return false;
            throw $e;
        }
    }

    public static function fail(\PDO $db, int $userId, string $operation, string $key): void
    {
        $stmt = $db->prepare('UPDATE idempotency_keys SET status = ? WHERE user_id = ? AND operation = ? AND idem_key = ?');
        $stmt->execute(['failed', $userId, $operation, $key]);
    }

    public static function complete(\PDO $db, int $userId, string $operation, string $key, ?int $resourceId, array $response = []): void
    {
        $stmt = $db->prepare('UPDATE idempotency_keys SET status = ?, resource_id = ?, response_json = ? WHERE user_id = ? AND operation = ? AND idem_key = ?');
        $stmt->execute(['completed', $resourceId, json_encode($response, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $userId, $operation, $key]);
    }
}
