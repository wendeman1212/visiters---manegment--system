<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\VisitRequest;
use App\Models\Visitor;
use App\Models\User;

$BASE = 'http://127.0.0.1:3255';
$log = '';

function req($url, $method = 'GET', $fields = [], $cookies = [], $headers = [], $jar = null) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true,
    ]);
    $h = ['Accept: application/json'];
    foreach ($headers as $k => $v) $h[] = "$k: $v";
    if ($cookies) {
        $parts = [];
        foreach ($cookies as $k => $v) $parts[] = "$k=$v";
        $h[] = 'Cookie: '.implode('; ', $parts);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    if ($jar) curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$code, substr($resp, 0, $headerSize), substr($resp, $headerSize)];
}

function parseCookies($headerText) {
    $cookies = [];
    foreach (explode("\n", $headerText) as $line) {
        if (stripos($line, 'Set-Cookie:') !== false) {
            $part = substr($line, strlen('Set-Cookie:'));
            $pair = trim(explode(';', $part)[0]);
            list($k, $v) = array_pad(explode('=', $pair, 2), 2, '');
            $cookies[$k] = $v;
        }
    }
    return $cookies;
}

// 0) create fresh pending visit for host A
$hostA = User::withoutGlobalScopes()->find(12);
$visitor = Visitor::withoutGlobalScopes()->where('company_id', 3)->first();
$visit = VisitRequest::withoutGlobalScopes()->create([
    'visitor_id' => $visitor->id, 'host_id' => $hostA->id,
    'department_id' => $hostA->department_id, 'branch_id' => $hostA->branch_id, 'company_id' => 3,
    'purpose' => 'REAL HTTP E2E', 'requested_time' => now()->addDay(), 'duration' => 60, 'status' => 'pending',
]);
$log .= "created visit #{$visit->id}\n";

// 1) login as host A
list($code, $hdr, $body) = req("$BASE/login", 'GET');
$jar = __DIR__.'/temp_cookies.txt';
@unlink($jar);
$cookies = parseCookies($hdr);
$log .= "GET /login -> {$code}, cookies: ".json_encode(array_keys($cookies))."\n";
$xsrf = rawurldecode($cookies['XSRF-TOKEN'] ?? '');

list($code, $hdr, $body) = req("$BASE/login", 'POST',
    ['email' => 'meki@gmail.com', 'password' => 'password123', 'remember' => '0'],
    $cookies, ['X-XSRF-TOKEN' => $xsrf, 'Content-Type' => 'application/x-www-form-urlencoded'], $jar);
$cookies = array_merge($cookies, parseCookies($hdr));
$log .= "POST /login -> {$code}, body: ".substr($body, 0, 80)."\n";

// 2) host A transfers to host B
$xsrf = rawurldecode($cookies['XSRF-TOKEN'] ?? '');
list($code, $hdr, $body) = req("$BASE/visit-requests/{$visit->id}/transfer", 'POST',
    ['visit_request_id' => $visit->id, 'new_host_id' => 33, 'reason' => 'real http'],
    $cookies, ['X-XSRF-TOKEN' => $xsrf, 'Content-Type' => 'application/x-www-form-urlencoded']);
$log .= "POST transfer -> {$code} body: ".substr($body, 0, 120)."\n";
$visit->refresh();
$log .= "after transfer: host={$visit->host_id} status={$visit->status}\n";

// 3) logout, login as host B
list($code, $hdr, $body) = req("$BASE/logout", 'POST', [], $cookies, ['X-XSRF-TOKEN' => $xsrf, 'Content-Type' => 'application/x-www-form-urlencoded']);
$cookies = array_merge($cookies, parseCookies($hdr));
$log .= "logout -> {$code}\n";
$xsrf = rawurldecode($cookies['XSRF-TOKEN'] ?? '');
list($code, $hdr, $body) = req("$BASE/login", 'POST',
    ['email' => 'tale@gmail.com', 'password' => 'password123', 'remember' => '0'],
    $cookies, ['X-XSRF-TOKEN' => $xsrf, 'Content-Type' => 'application/x-www-form-urlencoded'], $jar);
$cookies = array_merge($cookies, parseCookies($hdr));
$log .= "login as hostB -> {$code}\n";

// 4) host B dashboard
$xsrf = rawurldecode($cookies['XSRF-TOKEN'] ?? '');
list($code, $hdr, $body) = req("$BASE/host/dashboard", 'GET', [], $cookies, []);
$log .= "GET /host/dashboard -> {$code}, content-len=".strlen($body)."\n";
$pos = strpos($body, 'data-page');
$log .= "  data-page pos: ".var_export($pos, true)."\n";
if ($pos !== false) $log .= "  around: ".substr($body, max(0,$pos-40), 200)."\n";
if (preg_match('/data-page="app" type="application\/json">(.*?)<\/script>/s', $body, $m)) {
    $page = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
    if (!$page) { $log .= "  script present but JSON decode failed. raw: ".substr($m[1],0,300)."\n"; }
    else {
        $visits = $page['props']['visits'] ?? [];
        $log .= "  version={$page['version']} visit-count=".count($visits)."\n";
        foreach ($visits as $v) {
            $log .= "  #{$v['id']} status={$v['status']} host={$v['host_id']} req_time={$v['requested_time']} is_no_show=".json_encode($v['is_no_show'] ?? null)."\n";
        }
        $ids = array_column($visits, 'id');
        $log .= "  visit#{$visit->id} in hostB dash: " . (in_array($visit->id, $ids) ? 'YES' : 'NO') . "\n";
    }
} else {
    $log .= "  NO script data-page. body: ".substr($body, 0, 500)."\n";
}

file_put_contents(__DIR__.'/temp_realhttp_result.txt', $log);
echo "done\n";
