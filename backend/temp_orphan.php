<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->handle(\Illuminate\Http\Request::create('/login', 'GET'));

use App\Models\VisitRequest;
use App\Models\User;

$log = "== VisitRequests with host_id set, but scopes would HIDE them from that host's dashboard ==\n";
$log .= "(rule: host dashboard requires company_id = host.company_id AND (host.branch_id null OR request.branch_id = host.branch_id))\n\n";

$problems = 0;
foreach (VisitRequest::withoutGlobalScopes()->orderBy('id')->get() as $v) {
    $host = $v->host_id ? User::withoutGlobalScopes()->find($v->host_id) : null;
    if (!$host) continue;
    $hidden = false;
    $reasons = [];
    if ($v->company_id !== null && $host->company_id !== null && (int)$v->company_id !== (int)$host->company_id) {
        $hidden = true; $reasons[] = "company mismatch req={$v->company_id} host={$host->company_id}";
    }
    if ($host->branch_id && $v->branch_id !== null && (int)$v->branch_id !== (int)$host->branch_id) {
        $hidden = true; $reasons[] = "branch mismatch req=".($v->branch_id??'NULL')." host={$host->branch_id}";
    }
    if ($hidden) {
        $problems++;
        $log .= "#{$v->id} host={$v->host_id}({$host->name}) status={$v->status} req_br=".($v->branch_id??'NULL')." req_co=".($v->company_id??'NULL')." => ".implode('; ', $reasons)."\n";
    }
}
$log .= "\nTotal hidden-from-host records: {$problems}\n";

$log .= "\n== VisitRequests with host_id NULL (never assigned) ==\n";
$nullHosts = VisitRequest::withoutGlobalScopes()->whereNull('host_id')->get();
$log .= "count=".$nullHosts->count()."\n";
foreach ($nullHosts->take(20) as $v) {
    $log .= "#{$v->id} status={$v->status} br=".($v->branch_id??'NULL')." co=".($v->company_id??'NULL')." purpose=".substr($v->purpose??'',0,40)."\n";
}

$log .= "\n== All hosts (role=host, active) and their dashboard-visible pending count ==\n";
foreach (User::withoutGlobalScopes()->where('role','host')->where('status','active')->get() as $h) {
    $q = VisitRequest::withoutGlobalScopes()
        ->where(function($qq) use ($h) { $qq->where('host_id',$h->id)->orWhere('alternate_host_id',$h->id); });
    $q->where('company_id', $h->company_id);
    if ($h->branch_id) $q->where('branch_id', $h->branch_id);
    $log .= "#{$h->id} {$h->name} br=".($h->branch_id??'NULL')." co=".($h->company_id??'NULL')." visible_pending=".$q->where('status','pending')->count()." total=".$q->count()."\n";
}

file_put_contents(__DIR__.'/temp_orphan_result.txt', $log);
echo "done\n";
