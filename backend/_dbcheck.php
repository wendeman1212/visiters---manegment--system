<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$tables = array_map(fn($t) => array_values((array) $t)[0], DB::select('SHOW TABLES'));
echo 'has jobs table: ' . (in_array('jobs', $tables) ? 'yes' : 'no') . "\n";
if (in_array('jobs', $tables)) {
    echo 'pending jobs: ' . DB::table('jobs')->count() . "\n";
}
if (in_array('failed_jobs', $tables)) {
    echo 'failed jobs: ' . DB::table('failed_jobs')->count() . "\n";
}
