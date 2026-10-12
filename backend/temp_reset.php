<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->handle(\Illuminate\Http\Request::create('/login', 'GET'));
use App\Models\User;
foreach ([8, 10, 28, 30, 34, 45, 46] as $id) {
    $u = User::withoutGlobalScopes()->find($id);
    if ($u) { $u->forceFill(['password' => bcrypt('password123')])->save(); echo "reset {$u->email} (#{$id})\n"; }
}
