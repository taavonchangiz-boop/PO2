<?php
namespace WHCM\Domain;

use WHCM\Core\Bootstrap;
use WHCM\Core\Logger;
use WHCM\Core\Transaction;

final class JobQueue
{
    public static function enqueue(string $type, array $payload, int $delaySeconds = 0, int $maxAttempts = 5): int
    {
        if (!preg_match('/^[a-z][a-z0-9_.:-]{1,79}$/', $type)) throw new \InvalidArgumentException('Invalid job type');
        $db = Bootstrap::getDB();
        $runAt = date('Y-m-d H:i:s', time() + max(0, $delaySeconds));
        $stmt = $db->prepare('INSERT INTO jobs (type, payload_json, status, attempts, max_attempts, available_at, created_at) VALUES (?, ?, \'queued\', 0, ?, ?, CURRENT_TIMESTAMP)');
        $stmt->execute([$type, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), max(1, min($maxAttempts, 20)), $runAt]);
        return (int)$db->lastInsertId();
    }

    public static function claim(string $workerId, int $leaseSeconds = 300): ?array
    {
        $db = Bootstrap::getDB();
        $now = date('Y-m-d H:i:s');
        $leaseUntil = date('Y-m-d H:i:s', time() + max(30, min($leaseSeconds, 3600)));
        return Transaction::run(function($db) use ($workerId, $now, $leaseUntil) {
            $driver = Bootstrap::getConfig('database.driver', 'sqlite');
            if ($driver === 'mysql') {
                $stmt = $db->prepare("SELECT * FROM jobs WHERE (status='queued' AND available_at <= ?) OR (status='running' AND lease_until < ?) ORDER BY id ASC LIMIT 1 FOR UPDATE SKIP LOCKED");
            } else {
                $stmt = $db->prepare("SELECT * FROM jobs WHERE (status='queued' AND available_at <= ?) OR (status='running' AND lease_until < ?) ORDER BY id ASC LIMIT 1");
            }
            $stmt->execute([$now, $now]);
            $job = $stmt->fetch();
            if (!$job) return null;
            $attempts = (int)$job['attempts'] + 1;
            if ($attempts > (int)$job['max_attempts']) {
                $db->prepare("UPDATE jobs SET status='dead', updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([(int)$job['id']]);
                return null;
            }
            $leaseToken = bin2hex(random_bytes(32));
            $up = $db->prepare("UPDATE jobs SET status='running', attempts=?, worker_id=?, lease_until=?, lease_token=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
            $up->execute([$attempts, $workerId, $leaseUntil, $leaseToken, (int)$job['id']]);
            $job['attempts'] = $attempts; $job['worker_id'] = $workerId; $job['lease_until'] = $leaseUntil; $job['lease_token'] = $leaseToken;
            $job['payload'] = json_decode((string)$job['payload_json'], true) ?: [];
            return $job;
        });
    }

    public static function complete(int $id, ?array $result = null, ?string $leaseToken = null): void
    {
        $db = Bootstrap::getDB();
        $sql = "UPDATE jobs SET status='done', result_json=?, lease_until=NULL, lease_token=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='running'";
        $params = [$result === null ? null : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id];
        if ($leaseToken !== null) { $sql .= ' AND lease_token = ?'; $params[] = $leaseToken; }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        if ($stmt->rowCount() !== 1 && $leaseToken !== null) throw new \RuntimeException('Stale job lease: completion rejected.');
    }

    public static function fail(int $id, string $error, int $retryDelaySeconds = 30, ?string $leaseToken = null): void
    {
        $db = Bootstrap::getDB();
        $stmt = $db->prepare('SELECT attempts, max_attempts FROM jobs WHERE id=? LIMIT 1');
        $stmt->execute([$id]); $job = $stmt->fetch();
        if (!$job) return;
        $status = ((int)$job['attempts'] >= (int)$job['max_attempts']) ? 'dead' : 'queued';
        $available = date('Y-m-d H:i:s', time() + max(5, min($retryDelaySeconds, 86400)));
        $sql = "UPDATE jobs SET status=?, last_error=?, available_at=?, lease_until=NULL, lease_token=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='running'";
        $params = [$status, substr($error, 0, 2000), $available, $id];
        if ($leaseToken !== null) { $sql .= ' AND lease_token = ?'; $params[] = $leaseToken; }
        $up = $db->prepare($sql);
        $up->execute($params);
        if ($up->rowCount() !== 1 && $leaseToken !== null) {
            Logger::warning('stale_job_lease_rejected', ['job_id'=>$id]);
            return;
        }
        Logger::warning('job_failed', ['job_id'=>$id, 'status'=>$status]);
    }
}
