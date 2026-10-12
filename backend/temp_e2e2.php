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
$hostA = User::withoutGlobalScopes()->where('id',12)->first();
$hostB = User::withoutGlobalScopes()->where('id',33)->first();
$visitor = Visitor::withoutGlobalScopes()->where('company_id',3)->first();

$log = "";

function mkVisit($hostA, $visitor) {
    return VisitRequest::withoutGlobalScopes()->create([
        'visitor_id' => $visitor->id, 'host_id' => $hostA->id,
        'department_id' => $hostA->department_id, 'branch_id' => $hostA->branch_id, 'company_id' => $hostA->company_id,
        'purpose' => 'E2E TEST', 'requested_time' => now()->addDay(), 'duration' => 60, 'status' => 'pending',
    ]);
}

function tryStore($kernel, $user, $visit, $payload) {
    Auth::guard('web')->login($user);
    try {
        $r = app(VisitorTransferController::class)->store(Request::create("/visitor-transfer/{$visit->id}", 'POST', $payload), $visit->id);
        $r = ($r instanceof \Illuminate\Http\JsonResponse) ? 'json' : 'redirect';
    } catch (\Throwable $e) { $r = 'EX: '.$e->getMessage(); }
    Auth::guard('web')->logout();
    $visit->refresh();
    return $r;
}

// Case A: admin transfers to hostB with company/branch/dept fully selected (dept=15 = hostB dept)
$v = mkVisit($hostA, $visitor);
$log .= "CASE A visit #{$v->id}\n";
$log .= "  store: ".tryStore($kernel, $admin, $v, ['new_host_id'=>$hostB->id,'company_id'=>3,'branch_id'=>1,'department_id'=>15,'reason'=>'A'])."\n";
$log .= "  state: host={$v->host_id} status={$v->status} co={$v->company_id} br=".($v->branch_id??'NULL')." dept=".($v->department_id??'NULL')."\n";

// Case B: admin transfers to hostB, only company selected (no branch/dept)
$v2 = mkVisit($hostA, $visitor);
$log .= "CASE B visit #{$v2->id}\n";
$log .= "  store: ".tryStore($kernel, $admin, $v2, ['new_host_id'=>$hostB->id,'company_id'=>3,'reason'=>'B'])."\n";
$log .= "  state: host={$v2->host_id} status={$v2->status} co={$v2->company_id} br=".($v2->branch_id??'NULL')." dept=".($v2->department_id??'NULL')."\n";

// Case C: admin transfers to company-level host bine (8) with only company selected
$v3 = mkVisit($hostA, $visitor);
$log .= "CASE C visit #{$v3->id}\n";
$log .= "  store: ".tryStore($kernel, $admin, $v3, ['new_host_id'=>8,'company_id'=>3,'reason'=>'C'])."\n";
$log .= "  state: host={$v3->host_id} status={$v3->status} co={$v3->company_id} br=".($v3->branch_id??'NULL')." dept=".($v3->department_id??'NULL')."\n";

// Case D: admin transfers to hostB then hostB approves, check dashboards
$v4 = mkVisit($hostA, $visitor);
$log .= "CASE D visit #{$v4->id}\n";
$log .= "  store: ".tryStore($kernel, $admin, $v4, ['new_host_id'=>$hostB->id,'company_id'=>3,'branch_id'=>1,'reason'=>'D'])."\n";
$log .= "  state: host={$v4->host_id} status={$v4->status}\n";
Auth::guard('web')->login($hostB);
try {
    $r = app(VisitRequestController::class)->makeDecision(Request::create("/visit-requests/{$v4->id}/decision", 'POST', ['decision'=>'approve','comments'=>'ok']), $v4->id);
    $log .= "  approve: ".($r->getStatusCode() ?? 'ok')."\n";
} catch (\Throwable $e) { $log .= "  approve EX: ".$e->getMessage()."\n"; }
Auth::guard('web')->logout();
$v4->refresh();
$log .= "  state after approve: host={$v4->host_id} status={$v4->status}\n";

// Case E: Host-side transfer hostB->hostA on approved visit
Auth::guard('web')->login($hostB);
try {
    $r = app(HostTransferController::class)->store(Request::create("/visit-requests/{$v4->id}/transfer", 'POST', ['new_host_id'=>$hostA->id,'reason'=>'E']), $v4->id);
    $log .= "CASE E host handoff: ".($r->getStatusCode() ?? 'ok')."\n";
} catch (\Throwable $e) { $log .= "CASE E EX: ".$e->getMessage()."\n"; }
Auth::guard('web')->logout();
$v4->refresh();
$log .= "  state after handoff: host={$v4->host_id} alt=".($v4->alternate_host_id??'NULL')." status={$v4->status}\n";

file_put_contents(__DIR__.'/temp_e2e2_result.txt', $log);
echo "done\n";
