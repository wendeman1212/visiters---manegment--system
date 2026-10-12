<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->handle(new Illuminate\Http\Request);

use App\Models\VisitRequest;
use App\Models\Visitor;
use App\Models\User;
use App\Services\HostTransferService;
use Illuminate\Support\Facades\Auth;

echo "=== PRE-FLIGHT ===\n";
$hostA = User::withoutGlobalScopes()->where('role','host')->where('company_id',3)->whereNotNull('branch_id')->whereNotNull('department_id')->first();
$hostB = User::withoutGlobalScopes()->where('role','host')->where('company_id',3)->whereNotNull('branch_id')->whereNotNull('department_id')->where('id','!=',$hostA->id)->first();
echo "hostA: #{$hostA->id} {$hostA->name} (br={$hostA->branch_id}, dept={$hostA->department_id})\n";
echo "hostB: #{$hostB->id} {$hostB->name} (br={$hostB->branch_id}, dept={$hostB->department_id})\n";

$visitor = Visitor::withoutGlobalScopes()->where('company_id',3)->first();
echo "visitor: #{$visitor->id} {$visitor->name}\n";

// Create a fresh pending visit assigned to hostA in hostA's branch/dept
$visit = VisitRequest::withoutGlobalScopes()->create([
    'visitor_id' => $visitor->id,
    'host_id' => $hostA->id,
    'department_id' => $hostA->department_id,
    'branch_id' => $hostA->branch_id,
    'company_id' => 3,
    'purpose' => 'TRANSFER TEST',
    'requested_time' => now()->addDay(),
    'duration' => 60,
    'status' => 'pending',
]);
echo "\nCreated test visit #{$visit->id} (host={$visit->host_id}, br={$visit->branch_id}, dept={$visit->department_id})\n";

// helper: run the host dashboard query for a user
function hostDashboardIds($userId) {
    $host = User::withoutGlobalScopes()->find($userId);
    Auth::guard('web')->login($host);
    $ids = VisitRequest::where(function($q) use ($host) {
            $q->where('host_id', $host->id)->orWhere('alternate_host_id', $host->id);
        })->pluck('id');
    Auth::guard('web')->logout();
    return $ids->all();
}

echo "HOST A dashboard BEFORE transfer sees: " . implode(',', hostDashboardIds($hostA->id)) . "\n";
echo "HOST B dashboard BEFORE transfer sees: " . implode(',', hostDashboardIds($hostB->id)) . "\n";

// Simulate Host A transferring the visit to Host B (transferVisitor path)
Auth::guard('web')->login($hostA);
try {
    $svc = app(HostTransferService::class);
    $svc->transfer($visit, $hostB, $hostA, 'test transfer');
    echo "Transfer OK\n";
} catch (\Exception $e) {
    echo "Transfer FAILED: " . $e->getMessage() . "\n";
}
Auth::guard('web')->logout();

$visit->refresh();
echo "\nAFTER TRANSFER: visit #{$visit->id} host={$visit->host_id} alt=" . ($visit->alternate_host_id ?? 'NULL') . " status={$visit->status}\n";
echo "HOST A dashboard AFTER transfer sees: " . implode(',', hostDashboardIds($hostA->id)) . "\n";
echo "HOST B dashboard AFTER transfer sees: " . implode(',', hostDashboardIds($hostB->id)) . "\n";
