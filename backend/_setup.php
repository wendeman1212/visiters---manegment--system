<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\VisitRequest;
use Illuminate\Support\Facades\Hash;

User::withoutGlobalScopes()->where('id', 24)->update(['password' => Hash::make('Temp12345')]);
echo "pw set for 24\n";

$v = VisitRequest::withoutGlobalScopes()->find(84);
echo "visit84: status={$v->status} host={$v->host_id} esc_to={$v->escalated_to} company={$v->company_id}\n";

$hosts = User::withoutGlobalScopes()->where('role','host')->where('status','active')->where('company_id', 9)->get(['id','name','branch_id','department_id']);
foreach ($hosts as $h) { echo "host {$h->id}|{$h->name}|branch={$h->branch_id}|dept={$h->department_id}\n"; }
