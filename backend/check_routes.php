<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (Route::getRoutes() as $route) {
    if (str_contains($route->uri, 'branches')) {
        echo $route->methods[0] . ' ' . $route->uri . ' -> ' . $route->getName() . PHP_EOL;
    }
}
