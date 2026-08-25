<?php
/**
 * Wave K — Tenant Isolation / IDOR static gate.
 * This test intentionally does not connect to a database; it proves that the
 * security-critical source paths contain ownership predicates and that the
 * public click/webhook endpoints cannot operate on arbitrary tenant objects.
 */

$root = dirname(__DIR__);
$failures = [];

function mustContain(string $file, string $needle, string $label): void {
    global $root, $failures;
    $path = $root . '/' . $file;
    $src = file_get_contents($path);
    if ($src === false || strpos($src, $needle) === false) {
        $failures[] = $label . ' missing: ' . $file;
    }
}

function mustNotContain(string $file, string $needle, string $label): void {
    global $root, $failures;
    $path = $root . '/' . $file;
    $src = file_get_contents($path);
    if ($src !== false && strpos($src, $needle) !== false) {
        $failures[] = $label . ' found: ' . $file;
    }
}

// User-owned object APIs.
mustContain('app/Api/Controllers/ChannelApiController.php', 'getChannel($channelId, $tenant_id)', 'channel ownership check');
mustContain('app/Api/Controllers/PostApiController.php', 'WHERE id = ? AND tenant_id = ?', 'post ownership predicate');
mustContain('app/Api/Controllers/SupportApiController.php', 'WHERE id = ? AND user_id = ? LIMIT 1', 'ticket ownership predicate');
mustContain('app/Api/Controllers/SettingsApiController.php', 'DELETE FROM auto_replies WHERE id = ? AND tenant_id = ?', 'auto-reply ownership predicate');
mustContain('app/Api/Controllers/AnalyticsApiController.php', 'WHERE id = ? AND tenant_id = ? LIMIT 1', 'analytics ownership predicate');
mustContain('app/Api/Controllers/BillingApiController.php', 'WHERE pay.user_id = ?', 'payment list ownership predicate');

// Public endpoints must not accept arbitrary object combinations.
mustContain('app/Controllers/MainController.php', 'SELECT c.id, c.link_config', 'click channel lookup');
mustContain('app/Controllers/MainController.php', 'in_array($channel_id, array_map(\'intval\', $targetChannels), true)', 'click target-channel binding');
mustContain('app/Controllers/MainController.php', 'hash_equals($expectedSecret, $providedSecret)', 'webhook secret validation');
mustNotContain('app/Controllers/MainController.php', 'SELECT link_config FROM channels WHERE id = ? LIMIT 1', 'unscoped click channel lookup');

// Webhook registration must create a cryptographically random secret and scope writes by tenant.
mustContain('app/Domain/ChannelManager.php', 'random_bytes(32)', 'webhook secret generation');
mustContain('app/Domain/ChannelManager.php', 'WHERE id = ? AND tenant_id = ?', 'scoped webhook state update');

// Production-scale ownership indexes must exist in fresh schema and versioned migration.
mustContain('migrations/install.sql', 'idx_posts_tenant_status_id', 'posts tenant index');
mustContain('migrations/install.sql', 'CREATE TABLE IF NOT EXISTS api_tokens', 'api token fresh-install schema');
mustContain('app/Core/Bootstrap.php', 'CREATE TABLE IF NOT EXISTS api_tokens', 'api token automatic migration');
mustContain('app/Core/Bootstrap.php', 'v16_tenant_isolation_webhook_hardening', 'v16 migration');

if ($failures) {
    fwrite(STDERR, "Wave K FAIL\n" . implode("\n", array_map(fn($x) => '- ' . $x, $failures)) . "\n");
    exit(1);
}

echo "Wave K PASS — tenant isolation / IDOR static gate\n";
