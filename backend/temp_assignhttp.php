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

// 1) GET /request-visit for CSRF
list($code, $hdr, $body) = req("$BASE/request-visit", 'GET');
$jar = __DIR__.'/temp_cookies2.txt';
@unlink($jar);
$cookies = parseCookies($hdr);
$log .= "GET /request-visit -> {$code}, cookies: ".json_encode(array_keys($cookies))."\n";
$xsrf = rawurldecode($cookies['XSRF-TOKEN'] ?? '');
if (!$xsrf) { $log .= "NO XSRF\n"; file_put_contents(__DIR__.'/temp_assign_result.txt', $log); exit; }

// 2) Submit a visit request (future time, company 3, branch 1)
$future = date('Y-m-d\TH:i', strtotime('+3 days 10:30'));
$payload = [
    'visit_type' => 'individual',
    'visitor_name' => 'Assign Test Visitor '.rand(1000,9999),
    'id_document_no' => str_pad((string)rand(0,999999), 6, '0', STR_PAD_LEFT),
    'visitor_email' => 'assign'.rand(1000,9999).'@test.com',
    'visitor_phone' => '0911'.rand(100000,999999),
    'company_id' => 3,
    'branch_id' => 1,
    'purpose' => 'Business Meeting',
    'requested_time' => $future,
    'duration' => 60,
];
$log .= "POST /request-visit payload: ".json_encode($payload)."\n";
list($code, $hdr, $body) = req("$BASE/request-visit", 'POST', $payload, $cookies,
    ['X-XSRF-TOKEN' => $xsrf, 'Content-Type' => 'application/x-www-form-urlencoded'], $jar);
$log .= "POST /request-visit -> {$code}, body: ".substr(strip_tags($body), 0, 300)."\n";
if ($code === 302) {
    $log .= "Redirect to: ".preg_replace('/^.*?location:\s*/i', '', $hdr)."\n";
}

// 3) Find the newest visit and its assigned host
$newest = VisitRequest::withoutGlobalScopes()->where('purpose', 'Business Meeting')->orderByDesc('id')->first();
if (!$newest) { $log .= "NO VISIT CREATED\n"; file_put_contents(__DIR__.'/temp_assign_result.txt', $log); exit; }
$log .= "\nnewest visit #{$newest->id}: host_id=".($newest->host_id ?? 'NULL')." dept=".($newest->department_id ?? 'NULL')." br=".($newest->branch_id ?? 'NULL')." co=".($newest->company_id ?? 'NULL')." status={$newest->status} email=".$newest->visitor->email."\n";
$host = $newest->host_id ? User::withoutGlobalScopes()->find($newest->host_id) : null;
$log .= "assigned host: ".($host ? "#{$host->id} {$host->name} role={$host->role} br=".($host->branch_id ?? 'NULL')." dept=".($host->department_id ?? 'NULL')." co=".($host->company_id ?? 'NULL')." status={$host->status}" : 'NONE')."\n";

// 4) Login as assigned host and check dashboard
if ($host) {
    list($code, $hdr, $body) = req("$BASE/login", 'GET');
    $cookies = parseCookies($hdr);
    $xsrf = rawurldecode($cookies['XSRF-TOKEN'] ?? '');
    list($code, $hdr, $body) = req("$BASE/login", 'POST',
        ['email' => $host->email, 'password' => 'password123', 'remember' => '0'],
        $cookies, ['X-XSRF-TOKEN' => $xsrf, 'Content-Type' => 'application/x-www-form-urlencoded']);
    $cookies = array_merge($cookies, parseCookies($hdr));
    $log .= "login as {$host->email} -> {$code}\n";
    if ($code === 302) {
        list($code, $hdr, $body) = req("$BASE/host/dashboard", 'GET', [], $cookies, []);
        if (preg_match('/data-page="app" type="application\/json">(.*?)<\/script>/s', $body, $m)) {
            $page = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
            $visits = $page['props']['visits'] ?? [];
            $ids = array_column($visits, 'id');
            $in = in_array($newest->id, $ids);
            $log .= "dashboard visits=".count($visits)." | visit#{$newest->id} visible to assigned host: " . ($in ? 'YES' : 'NO') . "\n";
            if ($in) {
                foreach ($visits as $v) if ($v['id'] == $newest->id) $log .= "  in dash: status={$v['status']} req_time={$v['requested_time']} is_no_show=".json_encode($v['is_no_show'])."\n";
            }
        } else {
            $log .= "  no data-page in dashboard. code=$code\n";
        }
    }
}

file_put_contents(__DIR__.'/temp_assign_result.txt', $log);
echo "done\n";
