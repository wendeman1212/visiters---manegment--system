<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "app.url = " . config('app.url') . "\n";
echo "app.env = " . config('app.env') . "\n";
echo "qr.base_url = " . var_export(config('qr.base_url'), true) . "\n";
echo "qr.lan_ip = " . var_export(config('qr.lan_ip'), true) . "\n";
echo "qr.port = " . var_export(config('qr.port'), true) . "\n";
echo "detectLanIp = " . \App\Services\QrUrlService::detectLanIp() . "\n";
echo "QrUrlService::baseUrl = " . \App\Services\QrUrlService::baseUrl() . "\n";
echo "scanPayload = " . \App\Services\QrBadgeService::scanPayload('VMS-KO60Uzdx2HWqumOx') . "\n";
echo "config cached? " . (file_exists(__DIR__.'/bootstrap/cache/config.php') ? 'yes' : 'no') . "\n";
