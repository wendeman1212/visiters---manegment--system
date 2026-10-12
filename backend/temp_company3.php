<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->handle(\Illuminate\Http\Request::create('/login', 'GET'));
use App\Models\User;
$log = '';
$log .= "== company 3 roles ==\n";
foreach (User::withoutGlobalScopes()->where('company_id',3)->get() as $u) {
    $log .= "#{$u->id} {$u->name} role={$u->role} br=".($u->branch_id??'NULL')." dept=".($u->department_id??'NULL')."\n";
}
$log .= "\n== branches ==\n";
foreach (App\Models\Branch::withoutGlobalScopes()->where('company_id',3)->get() as $b) {
    $log .= "#{$b->id} {$b->name}\n";
}
$log .= "\n== departments ==\n";
foreach (App\Models\Department::withoutGlobalScopes()->where('company_id',3)->get() as $d) {
    $log .= "#{$d->id} {$d->name} br=".($d->branch_id??'NULL')."\n";
}
file_put_contents(__DIR__.'/temp_company3.txt', $log);
echo "done\n";
