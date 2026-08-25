<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/app/Core/Bootstrap.php';
\WHCM\Core\Bootstrap::run();
\WHCM\Core\RequestContext::start();
$workerId = gethostname() . ':' . getmypid();
$once = in_array('--once', $argv, true);
$max = 100;
$count = 0;
while ($count < $max) {
    $job = \WHCM\Domain\JobQueue::claim($workerId);
    if (!$job) { if ($once) break; usleep(500000); continue; }
    try {
        // Handlers are deliberately allow-listed. Never execute PHP/function names from DB.
        $handlers = [];
        $handler = $handlers[$job['type']] ?? null;
        if ($handler === null) throw new RuntimeException('No registered handler for job type: ' . $job['type']);
        $result = $handler($job['payload']);
        \WHCM\Domain\JobQueue::complete((int)$job['id'], is_array($result) ? $result : null, (string)($job['lease_token'] ?? ''));
    } catch (Throwable $e) {
        \WHCM\Domain\JobQueue::fail((int)$job['id'], $e->getMessage(), min(3600, 30 * max(1, (int)$job['attempts'])), (string)($job['lease_token'] ?? ''));
    }
    $count++;
    if ($once) break;
}
