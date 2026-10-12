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

function hostDashboardIds($userId) {
    $host = User::withoutGlobalScopes()->find($userId);
    Auth::guard('web')->login($host);
    $ids = VisitRequest::where(function($q) use ($host) {
            $q->where('host_id', $host->id)->orWhere('alternate_host_id', $host->id);
        })->pluck('id');
    Auth::guard('web')->logout();
    return $ids->all();
}

echo "=== FULL LIFECYCLE via Admin->Host transfer ===\n";
$admin = User::withoutGlobalScopes()->where('role','admin')->where('company_id',3)->first();
$hostB = User::withoutGlobalScopes()->where('role','host')->where('company_id',3)->whereNotNull('branch_id')->whereNotNull('department_id')->first();
$visitor = Visitor::withoutGlobalScopes()->where('company_id',3)->first();
echo "admin: #{$admin->id} {$admin->name} (co={$admin->company_id})\n";
echo "hostB: #{$hostB->id} {$hostB->name} (br={$hostB->branch_id}, dept={$hostB->department_id})\n";

// fresh pending visit for hostB (as if a host created it)
$visit = VisitRequest::withoutGlobalScopes()->create([
    'visitor_id' => $visitor->id,
    'host_id' => $hostB->id,
    'department_id' => $hostB->department_id,
    'branch_id' => $hostB->branch_id,
    'company_id' => 3,
    'purpose' => 'ADMIN TRANSFER TEST',
    'requested_time' => now()->addDay(),
    'duration' => 60,
    'status' => 'pending',
]);
echo "\nCreated test visit #{$visit->id} (host={$visit->host_id}, br={$visit->branch_id}, dept={$visit->department_id}, co={$visit->company_id})\n";

// Pick a different host in the SAME company, DIFFERENT branch (to test cross-branch relocation)
$hostC = User::withoutGlobalScopes()->where('role','host')->where('company_id',3)
    ->where('branch_id','!=',$hostB->branch_id)->whereNotNull('branch_id')->whereNotNull('department_id')->first();
echo "hostC: #{$hostC->id} {$hostC->name} (br={$hostC->branch_id}, dept={$hostC->department_id})\n";

if ($hostC) {
    Auth::guard('web')->login($admin);
    try {
        $svc = app(HostTransferService::class);
        $svc->transfer($visit, $hostC, $admin, 'admin cross-branch transfer', [
            'validateScope' => false,
            'moveToHostCompany' => true,
        ]);
        echo "Admin transfer OK\n";
    } catch (\Exception $e) {
        echo "Admin transfer FAILED: " . $e->getMessage() . "\n";
    }
    Auth::guard('web')->logout();

    $visit->refresh();
    echo "\nAFTER Admin->Host transfer: visit #{$visit->id} host={$visit->host_id} co={$visit->company_id} br=" . ($visit->branch_id ?? 'NULL') . " dept=" . ($visit->department_id ?? 'NULL') . " status={$visit->status}\n";
    echo "HOST B (old) dashboard sees: " . implode(',', hostDashboardIds($hostB->id)) . "\n";
    echo "HOST C (new) dashboard sees: " . implode(',', hostDashboardIds($hostC->id)) . "\n";
}
