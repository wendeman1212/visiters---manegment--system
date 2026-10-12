<?php
use App\Models\User;
use App\Models\Department;
use App\Models\Branch;

echo 'users cols: ', implode(', ', Schema::getColumnListing('users')), PHP_EOL;
echo 'hosts: ', User::where('role', 'host')->count(), PHP_EOL;
echo 'hosts with branch_id: ', User::where('role', 'host')->whereNotNull('branch_id')->count(), PHP_EOL;
echo 'hosts with department_id: ', User::where('role', 'host')->whereNotNull('department_id')->count(), PHP_EOL;
echo 'hosts with both: ', User::where('role', 'host')->whereNotNull('branch_id')->whereNotNull('department_id')->count(), PHP_EOL;
echo 'all users with branch_id: ', User::whereNotNull('branch_id')->count(), PHP_EOL;
echo 'all users with department_id: ', User::whereNotNull('department_id')->count(), PHP_EOL;
echo PHP_EOL;

echo '--- Sample hosts (id, name, company_id, branch_id, department_id, status) ---', PHP_EOL;
$hosts = User::withoutGlobalScopes()->where('role', 'host')->orderBy('id')->limit(15)->get(['id', 'name', 'company_id', 'branch_id', 'department_id', 'status']);
foreach ($hosts as $u) {
    echo $u->id, ' | ', $u->name, ' | company=', $u->company_id, ' | branch=', $u->branch_id ?: 'NULL', ' | dept=', $u->department_id ?: 'NULL', ' | ', $u->status, PHP_EOL;
}

echo PHP_EOL;
echo '--- Sample departments (id, name, company_id, branch_id) ---', PHP_EOL;
$depts = Department::withoutGlobalScopes()->orderBy('id')->limit(15)->get(['id', 'name', 'company_id', 'branch_id']);
foreach ($depts as $d) {
    echo $d->id, ' | ', $d->name, ' | company=', $d->company_id, ' | branch=', $d->branch_id ?: 'NULL', PHP_EOL;
}

echo PHP_EOL;
echo '--- Branches with company_id and is_active ---', PHP_EOL;
$branches = Branch::withoutGlobalScopes()->orderBy('id')->limit(15)->get(['id', 'name', 'company_id', 'is_active']);
foreach ($branches as $b) {
    echo $b->id, ' | ', $b->name, ' | company=', $b->company_id, ' | active=', $b->is_active, PHP_EOL;
}
