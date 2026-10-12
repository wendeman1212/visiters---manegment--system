<?php
use App\Http\Controllers\VisitorTransferController;
use App\Models\VisitRequest;
use App\Models\User;
use Illuminate\Http\Request;

$admin = User::withoutGlobalScopes()->where('role', 'admin')->where('company_id', 3)->first();
$visit = VisitRequest::withoutGlobalScopes()->where('company_id', 3)->first();

echo 'admin: ', $admin->name ?? 'NONE', ' (id=', $admin->id ?? '-', ' company=', $admin->company_id ?? '-', ')' . PHP_EOL;
echo 'visit: ', $visit->id ?? 'NONE', ' host=', $visit->host_id ?? 'NULL', ' branch=', $visit->branch_id ?? 'NULL', ' dept=', $visit->department_id ?? 'NULL' . PHP_EOL;
if (!$admin || !$visit) { echo "SKIP: missing admin or visit\n"; exit; }

Auth::guard('web')->login($admin);

function show($label, $resp) {
    $data = json_decode($resp->getContent(), true);
    echo '=== ' . $label . ' ===' . PHP_EOL;
    echo 'companies: ', count($data['companies'] ?? []), PHP_EOL;
    echo 'branches:  ', count($data['branches'] ?? []), PHP_EOL;
    if (isset($data['branches'])) {
        foreach ($data['branches'] as $b) echo '   branch ', $b['id'], ': ', $b['name'], PHP_EOL;
    }
    echo 'departments: ', count($data['departments'] ?? []), PHP_EOL;
    if (isset($data['departments'])) {
        foreach ($data['departments'] as $d) echo '   dept ', $d['id'], ': ', $d['name'], PHP_EOL;
    }
    echo 'hosts: ', count($data['hosts'] ?? []), PHP_EOL;
    if (isset($data['hosts'])) {
        foreach ($data['hosts'] as $h) echo '   host ', $h['id'], ': ', $h['name'], ' (branch=', $h['branch_id'] ?: 'NULL', ', dept=', $h['department_id'] ?: 'NULL', ')' . PHP_EOL;
    }
    echo PHP_EOL;
}

$c = new VisitorTransferController();
$v = $visit->id;

show('Step 1: company only', $c->options(Request::create("/visitor-transfer/$v/options?company_id=3", 'GET'), $v));
show('Step 2: company + branch 1', $c->options(Request::create("/visitor-transfer/$v/options?company_id=3&branch_id=1", 'GET'), $v));
show('Step 3: company + branch 1 + dept 4 (company-level Finance)', $c->options(Request::create("/visitor-transfer/$v/options?company_id=3&branch_id=1&department_id=4", 'GET'), $v));
show('Step 4: company + branch 1 + dept 22 (branch-1 Finance copy)', $c->options(Request::create("/visitor-transfer/$v/options?company_id=3&branch_id=1&department_id=22", 'GET'), $v));
