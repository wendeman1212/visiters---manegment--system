<?php
use App\Models\User;
use App\Models\Department;

echo '--- ALL hosts: id | name | company | branch | dept | status | role ---', PHP_EOL;
$hosts = User::withoutGlobalScopes()->where('role', 'host')->orderBy('company_id')->orderBy('id')->get(['id', 'name', 'company_id', 'branch_id', 'department_id', 'status']);
foreach ($hosts as $u) {
    echo $u->id, ' | ', $u->name, ' | company=', $u->company_id ?: 'NULL', ' | branch=', $u->branch_id ?: 'NULL', ' | dept=', $u->department_id ?: 'NULL', ' | ', $u->status, PHP_EOL;
}

echo PHP_EOL;
echo '--- dept names for host department_ids ---', PHP_EOL;
$deptIds = $hosts->pluck('department_id')->filter()->unique()->all();
foreach ($deptIds as $did) {
    $d = Department::withoutGlobalScopes()->find($did);
    echo 'dept ', $did, ': ', $d->name ?? '??', ' | branch=', $d->branch_id ?: 'NULL', ' | company=', $d->company_id ?: 'NULL', PHP_EOL;
}

echo PHP_EOL;
echo '--- canReceiveVisitors method exists? ---', PHP_EOL;
echo method_exists(new User, 'canReceiveVisitors') ? 'yes' : 'no', PHP_EOL;
echo 'isActive exists? ', method_exists(new User, 'isActive') ? 'yes' : 'no', PHP_EOL;

echo PHP_EOL;
echo '--- All departments of company 3 (with branch) ---', PHP_EOL;
$depts = Department::withoutGlobalScopes()->where('company_id', 3)->orderBy('branch_id')->orderBy('name')->get(['id', 'name', 'branch_id']);
foreach ($depts as $d) {
    echo $d->id, ' | ', $d->name, ' | branch=', $d->branch_id ?: 'NULL', PHP_EOL;
}
