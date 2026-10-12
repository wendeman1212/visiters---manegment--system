<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->handle(\Illuminate\Http\Request::create('/login', 'GET'));

use App\Models\VisitRequest;
use App\Models\Visitor;
use App\Models\User;
use App\Models\Department;
use App\Models\Branch;
use App\Models\Company;
use App\Http\Controllers\VisitRequestController;

$log = '';

// --- companies with active hosts ---
$companies = Company::withoutGlobalScopes()->where('is_active', true)->get();
$log .= "== Companies & branches ==\n";
foreach ($companies as $c) {
    $log .= "co#{$c->company_id} {$c->company_name} active=".($c->is_active?'Y':'N')."\n";
    foreach (Branch::withoutGlobalScopes()->where('company_id', $c->company_id)->get() as $b) {
        $log .= "    br#{$b->id} {$b->name} active=".($b->is_active?'Y':'N')."\n";
    }
}
$log .= "\n== Hosts (active, role=host) ==\n";
foreach (User::withoutGlobalScopes()->where('role','host')->where('status','active')->get() as $h) {
    $dept = $h->department_id ? Department::withoutGlobalScopes()->find($h->department_id) : null;
    $log .= "#{$h->id} {$h->name} co=".($h->company_id??'NULL')." br=".($h->branch_id??'NULL')." dept={$h->department_id}(".($dept?->name ?: 'N/A').")\n";
}

// --- simulate assignment for each company/branch/purpose ---
$purposes = ['Business Meeting','Job Interview','Delivery','Consultation','Client Visit','Government Affairs','Other','IT Support','Finance Meeting','Security Visit'];
$refStore = new ReflectionMethod(VisitRequestController::class, 'resolveDepartmentFromPurpose');
$refDept = new ReflectionMethod(VisitRequestController::class, 'resolveHostFromDepartment');
$refStore->setAccessible(true); $refDept->setAccessible(true);

$log .= "\n== Assignment simulation ==\n";
$fail = 0; $total = 0;
foreach ($companies as $c) {
    $coId = $c->company_id;
    $branches = Branch::withoutGlobalScopes()->where('company_id', $coId)->get();
    $branchChoices = $branches->pluck('id')->map(fn($id)=>(string)$id)->values()->all();
    if (empty($branchChoices)) $branchChoices[] = null;
    foreach ($branchChoices as $brId) {
        foreach ($purposes as $p) {
            $total++;
            $label = "co=$coId br=".($brId?:'NULL')." purpose='$p'";
            try {
                $dept = $refStore->invoke(null, $p, $brId ? (int)$brId : null, $coId);
                $host = $refDept->invoke(null, $dept, null, null, null, $coId, $brId ? (int)$brId : null);
                $ok = true;
                $hostLabel = "dept#{$dept->id}({$dept->name}) -> host#{$host->id}({$host->name},br=".($host->branch_id??'NULL').",co={$host->company_id})";
            } catch (\Throwable $e) {
                $ok = false; $fail++;
                $msg = $e->getMessage();
                if ($e instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
                    $msg = $e->getResponse()->getContent();
                }
                $hostLabel = "FAIL: ".substr($msg,0,110);
            }
            $log .= ($ok ? 'OK  ' : 'FAIL')." $label => $hostLabel\n";
        }
    }
}
$log .= "\nTotal=$total failures=$fail\n";

file_put_contents(__DIR__.'/temp_matrix_result.txt', $log);
echo "done\n";
