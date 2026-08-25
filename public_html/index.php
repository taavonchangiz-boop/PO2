<?php
declare(strict_types=1);

$privateRoot = realpath(__DIR__ . '/../postyar-private');
if ($privateRoot === false || !is_file($privateRoot . '/public/index.php')) {
    http_response_code(503);
    echo 'Postyar is not installed correctly.';
    exit;
}

// The public asset tree lives in public_html; the application stays outside the web root.
putenv('POSTYAR_PUBLIC_ASSETS_PATH=' . __DIR__ . '/assets');
putenv('POSTYAR_PUBLIC_ASSETS_URL=/assets');

require $privateRoot . '/public/index.php';
