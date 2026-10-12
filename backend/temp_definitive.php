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
$token = Str::random(40);
$session->put('_token', $token);

$hostA = User::withoutGlobalScopes()->where('id',12)->first();
$hostB = User::withoutGlobalScopes()->where('id',33)->first();
$visitor = Visitor::withoutGlobalScopes()->where('company_id',3)->first();

$log = '';

function dispatch($kernel, $session, $method, $url, $payload = [], $as = null) {
    if ($as) Auth::guard('web')->login($as);
    $req = Request::create($url, $method, $payload);
    $req->setLaravelSession($session);
    $req->headers->set('X-Requested-With', 'XMLHttpRequest');
    $req->headers->set('Accept', 'application/json');
    $resp = $kernel->handle($req);
    if ($as) Auth::guard('web')->logout();
    return $resp;
}

function dashIds($kernel, $session, $userId) {
    Auth::guard('web')->login(User::withoutGlobalScopes()->find($userId));
    $req = Request::create('/host/dashboard', 'GET');
    $req->setLaravelSession($session);
    $req->headers->set('X-Inertia', 'true');
    $req->headers->set('X-Inertia-Version', '');
    $resp = $kernel->handle($req);
    Auth::guard('web')->logout();
    $data = json_decode($resp->getContent(), true);
    if (!$data) return 'no-json '.$resp->getStatusCode().' '.substr($resp->getContent(),0,100);
    $visits = $data['props']['visits'] ?? null;
    if ($visits === null) return 'keys: '.implode(',', array_keys($data['props'] ?? []));
    $out = [];
    foreach ($visits as $v) $out[] = "#{$v['id']}:{$v['status']}";
    return implode(' ', $out);
}

// ---- HOST TRANSFER path ----
$v = VisitRequest::withoutGlobalScopes()->create([
    'visitor_id' => $visitor->id, 'host_id' => $hostA->id,
    'department_id' => $hostA->department_id, 'branch_id' => $hostA->branch_id, 'company_id' => 3,
    'purpose' => 'DEFINITIVE HOST TEST', 'requested_time' => now()->addDay(), 'duration' => 60, 'status' => 'pending',
]);
$log .= "HOST-TEST visit #{$v->id} created (host={$v->host_id})\n";

$resp = dispatch($kernel, $session, 'POST', "/visit-requests/{$v->id}/transfer", [
    'visit_request_id' => $v->id, 'new_host_id' => $hostB->id, 'reason' => 'definitive',
], $hostA);
$v->refresh();
$log .= "  POST /transfer status={$resp->getStatusCode()}\n";
$log .= "  after host transfer: host={$v->host_id} alt=".($v->alternate_host_id ?? 'NULL')." status={$v->status}\n";
$log .= "  hostA(12) dash: ".dashIds($kernel, $session, 12)."\n";
$log .= "  hostB(33) dash: ".dashIds($kernel, $session, 33)."\n";

// ---- ADMIN TRANSFER path ----
$v2 = VisitRequest::withoutGlobalScopes()->create([
    'visitor_id' => $visitor->id, 'host_id' => $hostA->id,
    'department_id' => $hostA->department_id, 'branch_id' => $hostA->branch_id, 'company_id' => 3,
    'purpose' => 'DEFINITIVE ADMIN TEST', 'requested_time' => now()->addDay(), 'duration' => 60, 'status' => 'pending',
]);
$log .= "ADMIN-TEST visit #{$v2->id} created (host={$v2->host_id})\n";
$resp = dispatch($kernel, $session, 'POST', "/visitor-transfer/{$v2->id}", [
    'new_host_id' => $hostB->id, 'company_id' => 3, 'reason' => 'definitive admin',
], User::withoutGlobalScopes()->where('role','admin')->where('company_id',3)->first());
$v2->refresh();
$log .= "  POST /visitor-transfer status={$resp->getStatusCode()}\n";
$log .= "  after admin transfer: host={$v2->host_id} alt=".($v2->alternate_host_id ?? 'NULL')." status={$v2->status} br=".($v2->branch_id ?? 'NULL')." dept=".($v2->department_id ?? 'NULL')."\n";
$log .= "  hostA(12) dash: ".dashIds($kernel, $session, 12)."\n";
$log .= "  hostB(33) dash: ".dashIds($kernel, $session, 33)."\n";

// ---- CEO TRANSFER path ----
$v3 = VisitRequest::withoutGlobalScopes()->create([
    'visitor_id' => $visitor->id, 'host_id' => $hostA->id,
    'department_id' => $hostA->department_id, 'branch_id' => $hostA->branch_id, 'company_id' => 3,
    'purpose' => 'DEFINITIVE CEO TEST', 'requested_time' => now()->addDay(), 'duration' => 60, 'status' => 'pending',
]);
$log .= "CEO-TEST visit #{$v3->id} created (host={$v3->host_id})\n";
$resp = dispatch($kernel, $session, 'POST', "/visitor-transfer/{$v3->id}", [
    'new_host_id' => $hostB->id, 'company_id' => 3, 'reason' => 'definitive ceo',
], User::withoutGlobalScopes()->where('role','ceo')->where('company_id',3)->first());
$v3->refresh();
$log .= "  POST /visitor-transfer status={$resp->getStatusCode()}\n";
$log .= "  after ceo transfer: host={$v3->host_id} alt=".($v3->alternate_host_id ?? 'NULL')." status={$v3->status}\n";
$log .= "  hostA(12) dash: ".dashIds($kernel, $session, 12)."\n";
$log .= "  hostB(33) dash: ".dashIds($kernel, $session, 33)."\n";

file_put_contents(__DIR__.'/temp_definitive_result.txt', $log);
echo "done\n";
