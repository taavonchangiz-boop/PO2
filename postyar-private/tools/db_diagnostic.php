<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$configFile = __DIR__ . '/../config/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "CONFIG_MISSING\n");
    exit(2);
}
$config = require $configFile;
$driver = (string)($config['database']['driver'] ?? '');
$mysql = $config['database']['mysql'] ?? [];

echo "POSTYAR DATABASE DIAGNOSTIC\n===========================\n";
echo 'PHP: ' . PHP_VERSION . "\n";
echo 'PDO: ' . (extension_loaded('pdo') ? 'YES' : 'NO') . "\n";
echo 'PDO MySQL: ' . (extension_loaded('pdo_mysql') ? 'YES' : 'NO') . "\n";
echo 'Config readable: YES' . "\n";
echo 'Driver: ' . $driver . "\n";
if ($driver !== 'mysql') {
    echo "DATABASE CONNECTION: SKIPPED (driver is not mysql)\n";
    exit(0);
}
echo 'Host: ' . ($mysql['host'] ?? '') . "\n";
echo 'Port: ' . ($mysql['port'] ?? '') . "\n";
echo 'Database: ' . ($mysql['database'] ?? '') . "\n";
echo 'User: ' . ($mysql['username'] ?? '') . "\n";
echo 'Password configured: ' . (!empty($mysql['password']) ? 'YES' : 'NO') . "\n";
echo 'Secret key configured: ' . (!empty($config['security']['secret_key']) || getenv('POSTYAR_SECRET_KEY') ? 'YES' : 'NO') . "\n\n";

try {
    $pdo = new PDO(
        'mysql:host=' . $mysql['host'] . ';port=' . $mysql['port'] . ';dbname=' . $mysql['database'] . ';charset=' . ($mysql['charset'] ?? 'utf8mb4'),
        $mysql['username'],
        $mysql['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    echo "DATABASE CONNECTION: SUCCESS\n";
    echo 'Server: ' . (string)$pdo->query('SELECT VERSION()')->fetchColumn() . "\n";
    echo 'Selected DB: ' . (string)$pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
    $count = (int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
    echo 'Table count: ' . $count . "\n";
    if ($count > 0) {
        $hasSchema = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='schema_migrations'")->fetchColumn();
        if ($hasSchema) {
            $rows = $pdo->query('SELECT version, executed_at FROM schema_migrations ORDER BY executed_at ASC, version ASC')->fetchAll();
            echo 'Migration count: ' . count($rows) . "\n";
            foreach ($rows as $row) echo '  - ' . $row['version'] . ' @ ' . $row['executed_at'] . "\n";
        }
    }
} catch (Throwable $e) {
    echo "DATABASE CONNECTION: FAILED\n";
    echo 'Exception: ' . get_class($e) . "\n";
    echo 'Code: ' . (string)$e->getCode() . "\n";
    echo 'Message: ' . $e->getMessage() . "\n";
    exit(1);
}
