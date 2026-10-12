<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (Illuminate\Support\Facades\Route::getRoutes() as $r) {
    if (in_array($r->uri, ['login', 'dashboard', 'host/dashboard', 'host/dashboard/{section}'])) {
        echo $r->getMethods()[0].' /'.$r->uri.' => '.$r->getActionName().PHP_EOL;
    }
}
