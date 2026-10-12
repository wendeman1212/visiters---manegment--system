<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Hash;
use App\Models\User;

$log = '';
foreach ([12, 33, 2] as $id) {
    $u = User::withoutGlobalScopes()->find($id);
    if ($u) {
        $u->password = Hash::make('password123');
        $u->save();
        $log .= "reset #{$id} {$u->email} role={$u->role}\n";
    }
}
file_put_contents(__DIR__.'/temp_pwreset.txt', $log);
echo $log;
