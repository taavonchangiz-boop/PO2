<?php
/** Static adversarial gate for Wave R.1. */
$router = file_get_contents(__DIR__.'/../app/Api/MobileApiRouter.php');
$main = file_get_contents(__DIR__.'/../app/Controllers/MainController.php');
$adv = file_get_contents(__DIR__.'/../app/Domain/Advertising.php');
$errors=[];
if (strpos($router, "RateLimit::consume(") === false) $errors[]='API general rate limiter must consume, not merely check.';
if (strpos($main, "web_ad_impression") === false) $errors[]='Web ad impressions lack a bounded rate limiter.';
if (strpos($main, "web_ad_click") === false) $errors[]='Web ad clicks lack a bounded rate limiter.';
if (strpos($adv, "INSERT OR IGNORE INTO ad_events") === false) $errors[]='SQLite ad-event uniqueness is not atomic.';
if (strpos($adv, "INSERT IGNORE INTO ad_events") === false) $errors[]='MySQL ad-event uniqueness is not atomic.';
if ($errors) { fwrite(STDERR, implode("\n",$errors)."\n"); exit(1); }
echo "Wave R.1 adversarial gate: PASS\n";
