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
use App\Http\Controllers\HostTransferController;

$log = '';

// find a branch-2 host and a branch-1 host in company 3
$hostB1 = User::withoutGlobalScopes()->where('role','host')->where('company_id',3)->where('branch_id',1)->first();
$hostB2 = User::withoutGlobalScopes()->where('role','host')->where('company_id',3)->where('branch_id',2)->first();
$log .= "hostB1: #{$hostB1->id} {$hostB1->name} br=1 dept=".($hostB1->department_id??'NULL')."\n";
$log .= "hostB2: #{$hostB2->id} {$hostB2->name} br=2 dept=".($hostB2->department_id??'NULL')."\n";
if (!$hostB2) { $log .= "NO branch-2 host found\n"; file_put_contents(__DIR__.'/temp_crossbranch_result.txt',$log); exit; }

$visitor = Visitor::withoutGlobalScopes()->where('company_id',3)->first();
$visit = VisitRequest::withoutGlobalScopes()->create([
    'visitor_id' => $visitor->id, 'host_id' => $hostB1->id,
    'department_id' => $hostB1->department_id, 'branch_id' => 1, 'company_id' => 3,
    'purpose' => 'CROSS-BRANCH HOST TRANSFER', 'requested_time' => now()->addDay(), 'duration' => 60, 'status' => 'pending',
]);
$log .= "visit #{$visit->id} created for hostB1 (br=1)\n";

// HOST->HOST transfer br1 -> br2 (NO moveToHostCompany)
Auth::guard('web')->login($hostB1);
try {
    $r = app(HostTransferController::class)->store(Request::create("/visit-requests/{$visit->id}/transfer", 'POST', ['visit_request_id'=>$visit->id,'new_host_id'=>$hostB2->id,'reason'=>'cross-branch']), $visit->id);
    $log .= "host transfer result=".($r->getStatusCode() ?? 'ok')."\n";
} catch (\Throwable $e) { $log .= "EX: ".$e->getMessage()."\n"; }
Auth::guard('web')->logout();
$visit->refresh();
$log .= "after transfer: host={$visit->host_id} br=".($visit->branch_id??'NULL')." dept=".($visit->department_id??'NULL')." status={$visit->status}\n";

// Does hostB2 (br=2) dashboard include it?
function sees($userId) {
    $host = App\Models\User::withoutGlobalScopes()->find($userId);
    Auth::guard('web')->login($host);
    $visits = App\Models\VisitRequest::where(function($q) use ($host) {
            $q->where('host_id', $host->id)->orWhere('alternate_host_id', $host->id);
        })->orderBy('requested_time','asc')->get();
    Auth::guard('web')->logout();
    return implode(', ', array_map(fn($v) => "#{$v->id}:{$v->status}", $visits->all()));
}
$log .= "hostB1(br1) sees: ".sees($hostB1->id)."\n";
$log .= "hostB2(br2) sees: ".sees($hostB2->id)."\n";

file_put_contents(__DIR__.'/temp_crossbranch_result.txt', $log);
echo "done\n";
