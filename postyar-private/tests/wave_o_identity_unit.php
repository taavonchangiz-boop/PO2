<?php
require_once __DIR__ . '/../app/Domain/AntiAbuse.php';
use WHCM\Domain\AntiAbuse;
$phones = ['09121234567', '+989121234567', '00989121234567', '989121234567'];
foreach ($phones as $p) {
    if (AntiAbuse::normalizePhone($p) !== '09121234567') exit(1);
}
if (AntiAbuse::normalizeChannelId('@MyChannel') !== 'mychannel') exit(2);
if (AntiAbuse::normalizeChannelId(' MYCHANNEL ') !== 'mychannel') exit(3);
echo "PASS: canonical identity normalization\n";
