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

$admin = User::withoutGlobalScopes()->where('role','admin')->where('company_id',3)->first(); // selam #2
$ceo   = User::withoutGlobalScopes()->where('role','ceo')->where('company_id',3)->first();   // CEO User #1
$hostA = User::withoutGlobalScopes()->where('role','host')->where('id',12)->first();          // meki (co=3 br=1 dept=4)
$hostB = User::withoutGlobalScopes()->where('role','host')->where('id',33)->first();          // tale (co=3 br=2 dept=15)
$visitor = Visitor::withoutGlobalScopes()->where('company_id',3)->first() ?? Visitor::withoutGlobalScopes()->first();
$log = "visitor #".($visitor->id ?? 'NULL')." co=".($visitor->company_id ?? 'NULL')."\n";
$log .= "---\n";
$log .= "admin #{$admin->id} {$admin->name} (co={$admin->company_id})\n";
$log .= "ceo #{$ceo->id} {$ceo->name} (co={$ceo->company_id})\n";
$log .= "hostA #{$hostA->id} (co={$hostA->company_id} br={$hostA->branch_id} dept={$hostA->department_id})\n";
$log .= "hostB #{$hostB->id} (co={$hostB->company_id} br={$hostB->branch_id} dept={$hostB->department_id})\n";

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
    $err = '';
    if ($resp->getStatusCode() === 302) {
        $err = (string) $session->get('errors');
        $flash = $session->get('_flash') ? '' : '';
        $err = (string)($session->get('error') ?? '');
    }
    return [$resp->getStatusCode(), $body, $err];
}

function mkVisit(Visitor $visitor, User $host, string $purpose): VisitRequest {
    return VisitRequest::withoutGlobalScopes()->create([
        'visitor_id' => $visitor->id, 'host_id' => $host->id,
        'department_id' => $host->department_id, 'branch_id' => $host->branch_id, 'company_id' => 3,
        'purpose' => $purpose, 'requested_time' => now()->addDay(), 'duration' => 60, 'status' => 'pending',
    ]);
}

function dbState(VisitRequest $v): string {
    $v->refresh();
    return "host={$v->host_id} alt=".($v->alternate_host_id ?? 'NULL')." status={$v->status} escalated_to=".($v->escalated_to ?? 'NULL');
}

function captureErr(\Closure $fn) {
    try { return [ 'ok', $fn() ]; } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
        $r = $e->getResponse();
        return ['abort', $r->getStatusCode() . ' ' . (method_exists($r,'getContent') ? substr($r->getContent(),0,200) : '')];
    } catch (\Throwable $e) { return ['ex', get_class($e).': '.$e->getMessage()]; }
}

// ── SCENARIO 1: Host A → Transfer → Admin (dedicated transfer panel) ──
$v1 = mkVisit($visitor, $hostA, 'S1 HOST->ADMIN TRANSFER');
$log .= "\n=== S1: Host #{$hostA->id} transfer visit #{$v1->id} to Admin #{$admin->id} via /transfer ===\n";
$log .= "  before: ".dbState($v1)."\n";
$r = dispatch($kernel, $session, 'POST', "/visit-requests/{$v1->id}/transfer", ['visit_request_id'=>$v1->id,'new_host_id'=>$admin->id,'reason'=>'S1'], $hostA);
$log .= "  status={$r[0]} body=".substr($r[1],0,120)." flashErr=".$r[2]."\n";
$log .= "  after:  ".dbState($v1)."\n";

// ── SCENARIO 2: Host A → Transfer → CEO ──
$v2 = mkVisit($visitor, $hostA, 'S2 HOST->CEO TRANSFER');
$log .= "\n=== S2: Host #{$hostA->id} transfer visit #{$v2->id} to CEO #{$ceo->id} via /transfer ===\n";
$log .= "  before: ".dbState($v2)."\n";
$r = dispatch($kernel, $session, 'POST', "/visit-requests/{$v2->id}/transfer", ['visit_request_id'=>$v2->id,'new_host_id'=>$ceo->id,'reason'=>'S2'], $hostA);
$log .= "  status={$r[0]} body=".substr($r[1],0,120)." flashErr=".$r[2]."\n";
$log .= "  after:  ".dbState($v2)."\n";

// ── SCENARIO 3: Host B → Transfer a visit NOT assigned to host B → must 403 ──
$v3 = mkVisit($visitor, $hostA, 'S3 UNASSIGNED');
$log .= "\n=== S3: Host #{$hostB->id} transfer visit #{$v3->id} (owned by host #{$hostA->id}) via /transfer ===\n";
$log .= "  before: ".dbState($v3)."\n";
$r = dispatch($kernel, $session, 'POST', "/visit-requests/{$v3->id}/transfer", ['visit_request_id'=>$v3->id,'new_host_id'=>$ceo->id,'reason'=>'S3'], $hostB);
$log .= "  status={$r[0]} body=".substr($r[1],0,160)." flashErr=".$r[2]."\n";
$log .= "  after:  ".dbState($v3)."\n";

// ── SCENARIO 4: Host A → Escalate → Admin/CEO (makeDecision path) ──
$v4 = mkVisit($visitor, $hostA, 'S4 HOST->ESCALATE CEO');
$log .= "\n=== S4: Host #{$hostA->id} escalate visit #{$v4->id} to CEO #{$ceo->id} via /host-decision ===\n";
$log .= "  before: ".dbState($v4)."\n";
$r = dispatch($kernel, $session, 'POST', "/visit-requests/{$v4->id}/host-decision", ['decision'=>'escalate','escalated_to'=>$ceo->id,'reason'=>'S4'], $hostA);
$log .= "  status={$r[0]} body=".substr($r[1],0,160)." flashErr=".$r[2]."\n";
$log .= "  after:  ".dbState($v4)."\n";

// ── SCENARIO 5: Host A approve own visit (makeDecision ownership check baseline) ──
$v5 = mkVisit($visitor, $hostA, 'S5 OWNER APPROVE');
$log .= "\n=== S5: Host #{$hostA->id} approve own visit #{$v5->id} ===\n";
$r = dispatch($kernel, $session, 'POST', "/visit-requests/{$v5->id}/host-decision", ['decision'=>'approve','comments'=>'ok'], $hostA);
$log .= "  status={$r[0]} body=".substr($r[1],0,160)." flashErr=".$r[2]."\n";
$log .= "  after:  ".dbState($v5)."\n";

// ── SCENARIO 6: Host B approve visit owned by A (makeDecision must 403) ──
$v6 = mkVisit($visitor, $hostA, 'S6 NON-OWNER APPROVE');
$log .= "\n=== S6: Host #{$hostB->id} approve visit #{$v6->id} (owned by A) ===\n";
$r = dispatch($kernel, $session, 'POST', "/visit-requests/{$v6->id}/host-decision", ['decision'=>'approve','comments'=>'nope'], $hostB);
$log .= "  status={$r[0]} body=".substr($r[1],0,200)." flashErr=".$r[2]."\n";
$log .= "  after:  ".dbState($v6)."\n";

file_put_contents(__DIR__.'/temp_definitive_result.txt', $log);
echo $log;
