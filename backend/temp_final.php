<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->handle(\Illuminate\Http\Request::create('/login', 'GET'));

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
$hostA = User::withoutGlobalScopes()->where('id',12)->first();
$hostB = User::withoutGlobalScopes()->where('id',33)->first();
$visitor = Visitor::withoutGlobalScopes()->where('company_id',3)->first();

$log = '';

function dashQuery($userId) {
    $host = App\Models\User::withoutGlobalScopes()->find($userId);
    Auth::guard('web')->login($host);
    $visits = App\Models\VisitRequest::where(function($q) use ($host) {
            $q->where('host_id', $host->id)->orWhere('alternate_host_id', $host->id);
        })
        ->orderBy('requested_time', 'asc')
        ->get();
    Auth::guard('web')->logout();
    $out = [];
    foreach ($visits as $v) $out[] = "#{$v->id}:{$v->status}";
    return implode(' ', $out);
}

function mkVisit($host, $visitor, $purpose) {
    return VisitRequest::withoutGlobalScopes()->create([
        'visitor_id' => $visitor->id, 'host_id' => $host->id,
        'department_id' => $host->department_id, 'branch_id' => $host->branch_id, 'company_id' => $host->company_id,
        'purpose' => $purpose, 'requested_time' => now()->addDay(), 'duration' => 60, 'status' => 'pending',
    ]);
}

// ============ 1) HOST -> HOST transfer ============
$v = mkVisit($hostA, $visitor, 'HOST->HOST');
$log .= "=== 1) HOST->HOST visit #{$v->id} (host={$v->host_id}) ===\n";
Auth::guard('web')->login($hostA);
try {
    $r = app(HostTransferController::class)->store(Request::create("/visit-requests/{$v->id}/transfer", 'POST', ['visit_request_id'=>$v->id,'new_host_id'=>$hostB->id,'reason'=>'host']), $v->id);
    $log .= "  result=".($r->getStatusCode() ?? 'ok')."\n";
} catch (\Throwable $e) { $log .= "  EX: ".$e->getMessage()."\n"; }
Auth::guard('web')->logout();
$v->refresh();
$log .= "  state: host={$v->host_id} alt=".($v->alternate_host_id ?? 'NULL')." status={$v->status}\n";
$log .= "  oldHost(12) sees: ".dashQuery(12)."\n";
$log .= "  newHost(33) sees: ".dashQuery(33)."\n";

// ============ 2) ADMIN -> HOST transfer ============
$v2 = mkVisit($hostA, $visitor, 'ADMIN->HOST');
$log .= "=== 2) ADMIN->HOST visit #{$v2->id} (host={$v2->host_id}) ===\n";
Auth::guard('web')->login($admin);
try {
    $r = app(VisitorTransferController::class)->store(Request::create("/visitor-transfer/{$v2->id}", 'POST', ['new_host_id'=>$hostB->id,'company_id'=>3,'reason'=>'admin']), $v2->id);
    $log .= "  result=".($r->getStatusCode() ?? 'ok')."\n";
} catch (\Throwable $e) { $log .= "  EX: ".$e->getMessage()."\n"; }
Auth::guard('web')->logout();
$v2->refresh();
$log .= "  state: host={$v2->host_id} alt=".($v2->alternate_host_id ?? 'NULL')." status={$v2->status}\n";
$log .= "  oldHost(12) sees: ".dashQuery(12)."\n";
$log .= "  newHost(33) sees: ".dashQuery(33)."\n";

// ============ 3) CEO -> HOST transfer ============
$v3 = mkVisit($hostA, $visitor, 'CEO->HOST');
$log .= "=== 3) CEO->HOST visit #{$v3->id} (host={$v3->host_id}) ===\n";
Auth::guard('web')->login($ceo);
try {
    $r = app(VisitorTransferController::class)->store(Request::create("/visitor-transfer/{$v3->id}", 'POST', ['new_host_id'=>$hostB->id,'company_id'=>3,'reason'=>'ceo']), $v3->id);
    $log .= "  result=".($r->getStatusCode() ?? 'ok')."\n";
} catch (\Throwable $e) { $log .= "  EX: ".$e->getMessage()."\n"; }
Auth::guard('web')->logout();
$v3->refresh();
$log .= "  state: host={$v3->host_id} alt=".($v3->alternate_host_id ?? 'NULL')." status={$v3->status}\n";
$log .= "  oldHost(12) sees: ".dashQuery(12)."\n";
$log .= "  newHost(33) sees: ".dashQuery(33)."\n";

// ============ 4) approved + transferred -> appointments ============
$v4 = mkVisit($hostA, $visitor, 'APPROVE-THEN-TRANSFER');
$log .= "=== 4) approve then transfer visit #{$v4->id} ===\n";
Auth::guard('web')->login($hostA);
try {
    $r = app(VisitRequestController::class)->makeDecision(Request::create("/visit-requests/{$v4->id}/decision", 'POST', ['decision'=>'approve','comments'=>'ok']), $v4->id);
    $log .= "  approve result=".($r->getStatusCode() ?? 'ok')."\n";
} catch (\Throwable $e) { $log .= "  approve EX: ".$e->getMessage()."\n"; }
Auth::guard('web')->logout();
$v4->refresh();
$log .= "  after approve: host={$v4->host_id} status={$v4->status}\n";
Auth::guard('web')->login($hostA);
try {
    $r = app(HostTransferController::class)->store(Request::create("/visit-requests/{$v4->id}/transfer", 'POST', ['visit_request_id'=>$v4->id,'new_host_id'=>$hostB->id,'reason'=>'after-approve']), $v4->id);
    $log .= "  transfer result=".($r->getStatusCode() ?? 'ok')."\n";
} catch (\Throwable $e) { $log .= "  transfer EX: ".$e->getMessage()."\n"; }
Auth::guard('web')->logout();
$v4->refresh();
$log .= "  state: host={$v4->host_id} alt=".($v4->alternate_host_id ?? 'NULL')." status={$v4->status}\n";
$log .= "  oldHost(12) sees: ".dashQuery(12)."\n";
$log .= "  newHost(33) sees: ".dashQuery(33)."\n";

file_put_contents(__DIR__.'/temp_final_result.txt', $log);
echo "done\n";
