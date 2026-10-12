<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->handle(new Illuminate\Http\Request);

$requests = \App\Models\VisitRequest::with('groupMembers')->where('visit_type','company')->get();
foreach ($requests as $v) {
    echo "Visit #{$v->id} | visitor_id={$v->visitor_id} | status={$v->status} | members_count={$v->groupMembers->count()} | members: " . $v->groupMembers->pluck('name')->implode(', ') . PHP_EOL;
}
echo "---VISITORS---" . PHP_EOL;
$visitors = \App\Models\Visitor::select('id','name','email','id_document_no')->get();
foreach ($visitors as $vis) {
    echo "Visitor #{$vis->id} | name={$vis->name} | email={$vis->email} | id_doc={$vis->id_document_no}" . PHP_EOL;
}
