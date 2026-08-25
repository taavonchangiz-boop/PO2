<?php
namespace WHCM\Core;

/**
 * Small transaction helper for critical state transitions.
 * Retries only transient database contention; application exceptions are not swallowed.
 */
class Transaction
{
    public static function run(callable $callback, int $retries = 3): mixed
    {
        $db = Bootstrap::getDB();
        $attempt = 0;
        while (true) {
            try {
                $db->beginTransaction();
                $result = $callback($db);
                $db->commit();
                return $result;
            } catch (\Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                $attempt++;
                if ($attempt > $retries || !self::isTransient($e)) throw $e;
                usleep(25000 * $attempt);
            }
        }
    }

    private static function isTransient(\Throwable $e): bool
    {
        $m = strtolower($e->getMessage());
        return str_contains($m, 'database is locked')
            || str_contains($m, 'deadlock')
            || str_contains($m, 'lock wait timeout')
            || str_contains($m, 'try restarting transaction');
    }
}
