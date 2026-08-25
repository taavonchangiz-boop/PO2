<?php
namespace WHCM\Core;

/**
 * Database-backed rate limiter.
 *
 * The legacy check()/hit() API remains for compatibility, while consume()
 * provides one atomic gate for security-sensitive operations.
 */
class RateLimit {
    public static function check(string $action, int $max_attempts = 5, int $lock_seconds = 60, ?string $subject = null): bool {
        $db = Bootstrap::getDB();
        $key = self::bucketAction($action, $subject);
        $ip = self::getIp();
        $now = time();
        self::cleanup($db, $lock_seconds);
        $stmt = $db->prepare('SELECT attempts, last_attempt FROM rate_limits WHERE ip = ? AND action = ? LIMIT 1');
        $stmt->execute([$ip, $key]);
        $row = $stmt->fetch();
        return !$row || (int)$row['last_attempt'] + $lock_seconds <= $now || (int)$row['attempts'] < $max_attempts;
    }

    public static function hit(string $action, int $lock_seconds = 60, ?string $subject = null): void {
        self::consume($action, PHP_INT_MAX, $lock_seconds, $subject, false);
    }

    /**
     * Atomically consume one attempt. Returns false when the bucket is locked.
     */
    public static function consume(string $action, int $max_attempts = 5, int $lock_seconds = 60, ?string $subject = null, bool $enforce = true): bool {
        $db = Bootstrap::getDB();
        $key = self::bucketAction($action, $subject);
        $ip = self::getIp();
        $now = time();
        $cutoff = $now - $lock_seconds;

        // One conditional UPDATE is the concurrency gate. The database row lock makes
        // the check-and-increment indivisible, so the N+1th concurrent request cannot
        // slip through after reading the same attempt count as another worker.
        $sql = "UPDATE rate_limits
                SET attempts = CASE WHEN last_attempt < ? THEN 1 ELSE attempts + 1 END,
                    last_attempt = ?
                WHERE ip = ? AND action = ?
                  AND (last_attempt < ? OR ? = 0 OR attempts < ?)";
        $stmt = $db->prepare($sql);
        $stmt->execute([$cutoff, $now, $ip, $key, $cutoff, $enforce ? 1 : 0, $max_attempts]);
        if ($stmt->rowCount() === 1) return true;

        // No bucket yet. Unique(ip,action) makes this insert race-safe. If another
        // worker wins the insert, retry the conditional UPDATE exactly once.
        try {
            $stmt = $db->prepare('INSERT INTO rate_limits (ip, action, attempts, last_attempt) VALUES (?, ?, 1, ?)');
            $stmt->execute([$ip, $key, $now]);
            return true;
        } catch (\PDOException $e) {
            if ((string)$e->getCode() !== '23000' && stripos($e->getMessage(), 'unique') === false) {
                throw $e;
            }
            $stmt = $db->prepare($sql);
            $stmt->execute([$cutoff, $now, $ip, $key, $cutoff, $enforce ? 1 : 0, $max_attempts]);
            return $stmt->rowCount() === 1;
        }
    }

    public static function clear(string $action, ?string $subject = null): void {
        $ip = self::getIp();
        $key = self::bucketAction($action, $subject);
        $db = Bootstrap::getDB();
        $stmt = $db->prepare('DELETE FROM rate_limits WHERE ip = ? AND action = ?');
        $stmt->execute([$ip, $key]);
    }

    private static function bucketAction(string $action, ?string $subject): string {
        if ($subject === null || $subject === '') return substr($action, 0, 100);
        return substr($action . ':' . hash('sha256', $subject), 0, 100);
    }

    private static bool $cleanupDone = false;

    private static function cleanup(\PDO $db, int $lock_seconds): void {
        // Cleanup is maintenance, not part of the hot-path gate. Run at most once
        // per PHP request and delete in bounded batches to avoid table-wide churn.
        if (self::$cleanupDone) return;
        self::$cleanupDone = true;
        $cutoff = time() - max($lock_seconds, 3600);
        try {
            $db->prepare('DELETE FROM rate_limits WHERE last_attempt < ? LIMIT 500')->execute([$cutoff]);
        } catch (\Throwable $e) {
            // SQLite versions without DELETE ... LIMIT: bounded maintenance is optional.
            try {
                $db->prepare('DELETE FROM rate_limits WHERE rowid IN (SELECT rowid FROM rate_limits WHERE last_attempt < ? LIMIT 500)')->execute([$cutoff]);
            } catch (\Throwable $ignored) {}
        }
    }

    private static function getIp(): string {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $trusted = Bootstrap::getConfig('security.trusted_proxies', []);
        if (!empty($trusted) && in_array($ip, $trusted, true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            foreach (explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']) as $candidate) {
                $candidate = trim($candidate);
                if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return $candidate;
            }
        }
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '127.0.0.1';
    }
}
