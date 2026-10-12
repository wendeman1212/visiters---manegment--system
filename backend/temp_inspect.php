<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->handle(new Illuminate\Http\Request);

echo "=== VISIT REQUESTS ===\n";
foreach (\App\Models\VisitRequest::withoutGlobalScopes()->orderBy('id')->get() as $v) {
    echo "Visit #{$v->id} | host={$v->host_id} | alt=" . ($v->alternate_host_id ?: 'NULL') . " | status={$v->status} | visitor={$v->visitor_id} | co={$v->company_id} | br=" . ($v->branch_id ?: 'NULL') . " | dept=" . ($v->department_id ?: 'NULL') . "\n";
}

echo "\n=== USERS (host/admin/ceo) ===\n";
foreach (\App\Models\User::withoutGlobalScopes()->whereIn('role', ['host','admin','ceo'])->orderBy('id')->get() as $u) {
    echo "User #{$u->id} | {$u->name} | role={$u->role} | co={$u->company_id} | br=" . ($u->branch_id ?: 'NULL') . " | dept=" . ($u->department_id ?: 'NULL') . " | status={$u->status}\n";
}

echo "\n=== HOST TRANSFERS ===\n";
if (\Illuminate\Support\Facades\Schema::hasTable('host_transfers')) {
    foreach (\App\Models\HostTransfer::orderBy('id')->get() as $t) {
        echo "Transfer #{$t->id} | visit={$t->visit_request_id} | prev={$t->previous_host_id} | new={$t->new_host_id} | by={$t->transferred_by} | created={$t->created_at}\n";
    }
} else {
    echo "no host_transfers table\n";
}
