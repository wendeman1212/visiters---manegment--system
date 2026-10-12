<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;

$candidates = ['password', '123456', 'Password123', 'password123', 'admin123'];
$users = User::withoutGlobalScopes()->whereIn('role', ['admin', 'ceo'])->get(['id', 'name', 'email', 'role']);
foreach ($users as $u) {
    $match = '?';
    foreach ($candidates as $c) {
        if (Hash::check($c, $u->password)) { $match = $c; break; }
    }
    echo "{$u->id}|{$u->name}|{$u->email}|{$u->role}|pw={$match}\n";
}
