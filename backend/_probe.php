<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$v = App\Models\VisitRequest::withoutGlobalScopes()->find(192);
echo "visit 192: status={$v->status} escalated_to=".var_export($v->escalated_to, true)." admin_sla_started={$v->admin_sla_started_at}\n";
$esc = App\Models\Escalation::where('visit_request_id', 192)->orderByDesc('id')->first();
echo $esc ? "escalation: id={$esc->id} by={$esc->escalated_by} to={$esc->escalated_to} level={$esc->level} trigger={$esc->trigger_type}\n" : "no escalation\n";
echo "throttle key present: ".var_export(Illuminate\Support\Facades\Cache::has('vms:sla-auto-run'), true)."\n";
