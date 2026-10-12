<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->handle(Illuminate\Http\Request::create('/login', 'GET'));

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\VisitRequest;
use App\Models\Visitor;
use App\Http\Controllers\VisitorTransferController;
use App\Http\Controllers\VisitRequestController;
use App\Http\Controllers\HostTransferController;

$admin = User::withoutGlobalScopes()->where('role','admin')->where('company_id',3)->first();
$ceo   = User::withoutGlobalScopes()->where('role','ceo')->where('company_id',3)->first();
$hostA = User::withoutGlobalScopes()->where('role','host')->where('id',12)->first();
$hostB = User::withoutGlobalScopes()->where('role','host')->where('id',33)->first();
$visitor = Visitor::withoutGlobalScopes()->where('company_id',3)->first();

$log = "";
$log .= "admin #{$admin->id} {$admin->name}\n";
$log .= "ceo #{$ceo->id} {$ceo->name}\n";
$log .= "hostA #{$hostA->id} br=".($hostA->branch_id??'NULL')." dept=".($hostA->department_id??'NULL')."\n";
$log .= "hostB #{$hostB->id} br=".($hostB->branch_id??'NULL')." dept=".($hostB->department_id??'NULL')."\n";

function dashList($kernel, $userId) {
    $u = App\Models\User::withoutGlobalScopes()->find($userId);
    Auth::guard('web')->login($u);
    $resp = $kernel->handle(Request::create('/host/dashboard', 'GET', [], [], [], ['HTTP_X_INERTIA' => 'true']));
    $data = json_decode($resp->getContent(), true);
    Auth::guard('web')->logout();
    if (!$data) return 'no-json '.$resp->getStatusCode().' '.substr($resp->getContent(),0,80);
    $visits = $data['props']['visits'] ?? null;
    if ($visits === null) return 'no-visits: '.implode(',', array_keys($data['props'] ?? []));
    $out = [];
    foreach ($visits as $v) $out[] = "#{$v['id']}({$v['status']})";
    return implode(' ', $out);
}

$visit = VisitRequest::withoutGlobalScopes()->create([
    'visitor_id' => $visitor->id, 'host_id' => $hostA->id,
    'department_id' => $hostA->department_id, 'branch_id' => $hostA->branch_id, 'company_id' => 3,
    'purpose' => 'E2E TEST', 'requested_time' => now()->addDay(), 'duration' => 60, 'status' => 'pending',
]);
$log .= "created visit #{$visit->id}\n";

// 1) Admin -> Host transfer
Auth::guard('web')->login($admin);
try {
    $r = app(VisitorTransferController::class)->store(Request::create("/visitor-transfer/{$visit->id}", 'POST', ['new_host_id'=>$hostB->id,'reason'=>'e2e admin']), $visit->id);
    $log .= "ADMIN transfer returned status ".($r->getStatusCode() ?? 'redirect')."\n";
} catch (\Throwable $e) { $log .= "ADMIN transfer EX: ".$e->getMessage()."\n"; }
Auth::guard('web')->logout();
$visit->refresh();
$log .= "after admin transfer: host={$visit->host_id} alt=".($visit->alternate_host_id??'NULL')." status={$visit->status} company={$visit->company_id} br=".($visit->branch_id??'NULL')." dept=".($visit->department_id??'NULL')."\n";
$log .= "  hostA dash: ".dashList($kernel, $hostA->id)."\n";
$log .= "  hostB dash: ".dashList($kernel, $hostB->id)."\n";

// 2) Host B approves
Auth::guard('web')->login($hostB);
try {
    $r = app(VisitRequestController::class)->makeDecision(Request::create("/visit-requests/{$visit->id}/decision", 'POST', ['decision'=>'approve','comments'=>'ok']), $visit->id);
    $log .= "HOSTB approve returned ".($r->getStatusCode() ?? 'ok')."\n";
} catch (\Throwable $e) { $log .= "HOSTB approve EX: ".$e->getMessage()."\n"; }
Auth::guard('web')->logout();
$visit->refresh();
$log .= "after approve: status={$visit->status} host={$visit->host_id}\n";
$log .= "  hostB dash: ".dashList($kernel, $hostB->id)."\n";

// 3) Host -> Host transfer (hostB -> hostA) on approved visit
Auth::guard('web')->login($hostB);
try {
    $r = app(HostTransferController::class)->store(Request::create("/visit-requests/{$visit->id}/transfer", 'POST', ['new_host_id'=>$hostA->id,'reason'=>'host handoff']), $visit->id);
    $log .= "HOST handoff returned ".($r->getStatusCode() ?? 'ok')."\n";
} catch (\Throwable $e) { $log .= "HOST handoff EX: ".$e->getMessage()."\n"; }
Auth::guard('web')->logout();
$visit->refresh();
$log .= "after host transfer: host={$visit->host_id} alt=".($visit->alternate_host_id??'NULL')." status={$visit->status}\n";
$log .= "  hostA dash: ".dashList($kernel, $hostA->id)."\n";
$log .= "  hostB dash: ".dashList($kernel, $hostB->id)."\n";

// 4) CEO -> Host transfer
$visit2 = VisitRequest::withoutGlobalScopes()->create([
    'visitor_id' => $visitor->id, 'host_id' => $hostA->id,
    'department_id' => $hostA->department_id, 'branch_id' => $hostA->branch_id, 'company_id' => 3,
    'purpose' => 'E2E TEST CEO', 'requested_time' => now()->addDay(), 'duration' => 60, 'status' => 'pending',
]);
$log .= "created visit2 #{$visit2->id}\n";
Auth::guard('web')->login($ceo);
try {
    $r = app(VisitorTransferController::class)->store(Request::create("/visitor-transfer/{$visit2->id}", 'POST', ['new_host_id'=>$hostB->id,'reason'=>'e2e ceo']), $visit2->id);
    $log .= "CEO transfer returned ".($r->getStatusCode() ?? 'ok')."\n";
} catch (\Throwable $e) { $log .= "CEO transfer EX: ".$e->getMessage()."\n"; }
Auth::guard('web')->logout();
$visit2->refresh();
$log .= "after ceo transfer: host={$visit2->host_id} status={$visit2->status}\n";
$log .= "  hostB dash: ".dashList($kernel, $hostB->id)."\n";

file_put_contents(__DIR__.'/temp_e2e_result.txt', $log);
echo "done\n";
