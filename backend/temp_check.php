<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$out = '';
$out .= "dept15: ".json_encode(App\Models\Department::withoutGlobalScopes()->find(15)?->toArray()).PHP_EOL;
$out .= "dept4: ".json_encode(App\Models\Department::withoutGlobalScopes()->find(4)?->toArray()).PHP_EOL;
$out .= "branches co3: ".json_encode(App\Models\Branch::withoutGlobalScopes()->where('company_id',3)->get(['id','name'])->toArray()).PHP_EOL;
file_put_contents(__DIR__.'/temp_dept.txt', $out);
echo 'ok';
