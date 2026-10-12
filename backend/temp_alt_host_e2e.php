<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->handle(\Illuminate\Http\Request::create('/login', 'GET'));

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\VisitRequest;
use App\Models\Visitor;
use Illuminate\Support\Str;

$session = app('session')->driver();
$session->start();
$session->put('_token', Str::random(40));
$session->put('_previous.url', '/host/dashboard');

$admin = User::withoutGlobalScopes()->where('role','admin')->where('company_id',3)->first();
$ceo   = User::withoutGlobalScopes()->where('role','ceo')->where('company_id',3)->first();
$hostA = User::withoutGlobalScopes()->where('role','host')->where('id',12)->first();
$hostB = User::withoutGlobalScopes()->where('role','host')->where('id',33)->first();
$visitor = Visitor::withoutGlobalScopes()->first();

function dispatch($kernel, $session, $method, $url, $payload = [], $as = null) {
    if ($as) Auth::guard('web')->login($as);
    $payload = array_merge(['_token' => $session->get('_token')], $payload);
    $req = Request::create($url, $method, $payload);
    $req->setLaravelSession($session);
    $req->headers->set('X-Requested-With', 'XMLHttpRequest');
    $req->headers->set('Accept', 'application/json');
    $resp = $kernel->handle($req);
    if ($as) Auth::guard('web')->logout();
    $body = '';
    try { $body = $resp->getContent(); } catch (\Throwable $e) {}
    if ($resp->getStatusCode() === 200) {
        try { $j = json_decode($body, true); $body = $j['message'] ?? $j['error'] ?? substr($body,0,120); } catch (\Throwable $e) {}
    }
    return [$resp->getStatusCode(), $body];
}

function state(VisitRequest $v): string {
    $v->refresh();
    return "host={$v->host_id} alt=".($v->alternate_host_id ?? 'NULL')." status={$v->status} esc_to=".($v->escalated_to ?? 'NULL');
}

$log = "hostA #{$hostA->id}, hostB #{$hostB->id}, admin #{$admin->id}, ceo #{$ceo->id}\n";

// ALT scenario: visit owned by hostA, alternate host = hostB
$v1 = VisitRequest::withoutGlobalScopes()->create([
    'visitor_id' => $visitor->id, 'host_id' => $hostA->id, 'alternate_host_id' => $hostB->id,
    'department_id' => $hostA->department_id, 'branch_id' => $hostA->branch_id, 'company_id' => 3,
    'purpose' => 'ALT TRANSFER', 'requested_time' => now()->addDay(), 'duration' => 60, 'status' => 'pending',
]);
$log .= "\n=== ALT-1: ALTERNATE host #{$hostB->id} transfer visit #{$v1->id} to Admin #{$admin->id} via /transfer ===\n";
$log .= "  before: ".state($v1)."\n";
$r = dispatch($kernel, $session, 'POST', "/visit-requests/{$v1->id}/transfer", ['visit_request_id'=>$v1->id,'new_host_id'=>$admin->id,'reason'=>'alt'], $hostB);
$log .= "  status={$r[0]} body=".substr($r[1],0,120)."\n";
$log .= "  after:  ".state($v1)."\n";

$v2 = VisitRequest::withoutGlobalScopes()->create([
    'visitor_id' => $visitor->id, 'host_id' => $hostA->id, 'alternate_host_id' => $hostB->id,
    'department_id' => $hostA->department_id, 'branch_id' => $hostA->branch_id, 'company_id' => 3,
    'purpose' => 'ALT ESCALATE', 'requested_time' => now()->addDay(), 'duration' => 60, 'status' => 'pending',
]);
$log .= "\n=== ALT-2: ALTERNATE host #{$hostB->id} escalate visit #{$v2->id} to CEO #{$ceo->id} via /host-decision ===\n";
$log .= "  before: ".state($v2)."\n";
$r = dispatch($kernel, $session, 'POST', "/visit-requests/{$v2->id}/host-decision", ['decision'=>'escalate','escalated_to'=>$ceo->id,'reason'=>'alt'], $hostB);
$log .= "  status={$r[0]} body=".substr($r[1],0,120)."\n";
$log .= "  after:  ".state($v2)."\n";

$v3 = VisitRequest::withoutGlobalScopes()->create([
    'visitor_id' => $visitor->id, 'host_id' => $hostA->id, 'alternate_host_id' => $hostB->id,
    'department_id' => $hostA->department_id, 'branch_id' => $hostA->branch_id, 'company_id' => 3,
    'purpose' => 'ALT APPROVE', 'requested_time' => now()->addDay(), 'duration' => 60, 'status' => 'pending',
]);
$log .= "\n=== ALT-3: ALTERNATE host #{$hostB->id} approve visit #{$v3->id} via /host-decision ===\n";
$log .= "  before: ".state($v3)."\n";
$r = dispatch($kernel, $session, 'POST', "/visit-requests/{$v3->id}/host-decision", ['decision'=>'approve','comments'=>'ok'], $hostB);
$log .= "  status={$r[0]} body=".substr($r[1],0,120)."\n";
$log .= "  after:  ".state($v3)."\n";

echo $log;
file_put_contents(__DIR__.'/temp_alt_result.txt', $log);
