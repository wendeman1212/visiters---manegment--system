<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->handle(\Illuminate\Http\Request::create('/login', 'GET'));

use App\Models\VisitRequest;
use App\Models\Visitor;
use App\Models\User;

$log = '';
foreach (['casea','caseb','casec','cased'] as $prefix) {
    $visitors = Visitor::withoutGlobalScopes()->where('email','like',"{$prefix}%@test.com")->get();
    foreach ($visitors as $vis) {
        $v = VisitRequest::withoutGlobalScopes()->where('visitor_id', $vis->id)->orderByDesc('id')->first();
        if (!$v) { $log .= "[$prefix] visitor {$vis->email} has NO visit request\n"; continue; }
        $host = $v->host_id ? User::withoutGlobalScopes()->find($v->host_id) : null;
        $log .= "[$prefix] visit #{$v->id} status={$v->status} host=".($v->host_id??'NULL').($host?"({$host->name},br=".($host->branch_id??'NULL').",co={$host->company_id})":'')." req_br=".($v->branch_id??'NULL')." req_co=".($v->company_id??'NULL')."\n";
    }
    if ($visitors->isEmpty()) $log .= "[$prefix] no visitor created\n";
}
file_put_contents(__DIR__.'/temp_cases_db.txt', $log);
echo "done\n";
