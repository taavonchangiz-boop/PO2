<?php
/**
 * Wave W — static/structural scalability gate.
 * This gate deliberately fails closed when required concurrency primitives disappear.
 */
$root = dirname(__DIR__);
$checks = [];
$add = static function(string $name, bool $ok, string $detail = '') use (&$checks): void {
    $checks[] = [$name, $ok, $detail];
};

$bootstrap = file_get_contents($root . '/app/Core/Bootstrap.php') ?: '';
$tx = file_get_contents($root . '/app/Core/Transaction.php') ?: '';
$queue = file_get_contents($root . '/app/Domain/JobQueue.php') ?: '';
$worker = file_get_contents($root . '/worker.php') ?: '';
$quota = file_get_contents($root . '/app/Domain/Quota.php') ?: '';
$rate = file_get_contents($root . '/app/Core/RateLimit.php') ?: '';

$add('SQLite foreign keys enabled', str_contains($bootstrap, 'PRAGMA foreign_keys = ON'));
$add('SQLite busy timeout configured', str_contains($bootstrap, 'PRAGMA busy_timeout = 5000'));
$add('SQLite WAL requested', str_contains($bootstrap, 'PRAGMA journal_mode = WAL'));
$add('MySQL native prepares', str_contains($bootstrap, 'ATTR_EMULATE_PREPARES') && str_contains($bootstrap, 'false'));
$add('Transaction retries transient contention', str_contains($tx, "database is locked") && str_contains($tx, 'deadlock'));
$add('Job leases are fenced', str_contains($queue, 'lease_token') && str_contains($worker, 'lease_token'));
$add('MySQL job claim uses SKIP LOCKED', str_contains($queue, 'FOR UPDATE SKIP LOCKED'));
$add('Post quota uses atomic reservation', str_contains($quota, "status = 'sending'") && str_contains($quota, 'BEGIN IMMEDIATE'));
$add('Rate limiter has atomic conditional update', str_contains($rate, 'UPDATE rate_limits') && str_contains($rate, 'attempts + 1'));
$add('Rate limiter has unique race guard', str_contains($rate, 'unique') && str_contains((string)file_get_contents($root . '/migrations/install.sql'), 'uq_rate_limits_ip_action'));
$add('Scale migration exists', is_file($root . '/migrations/v27_scale_concurrency_hardening.sql') && is_file($root . '/migrations/v27_scale_concurrency_hardening_mysql.sql'));

$errors = array_filter($checks, static fn($c) => !$c[1]);
foreach ($checks as [$name, $ok, $detail]) {
    echo ($ok ? 'PASS' : 'FAIL') . " | $name" . ($detail !== '' ? " | $detail" : '') . PHP_EOL;
}
echo 'SUMMARY | checks=' . count($checks) . ' failures=' . count($errors) . PHP_EOL;
exit($errors ? 1 : 0);
