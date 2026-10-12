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
    curl_setopt_array($ch, [CURLOPT_URL=>$url, CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_HEADER=>true]);
    $h = ['Accept: application/json'];
    foreach ($headers as $k => $v) $h[] = "$k: $v";
    if ($cookies) { $parts = []; foreach ($cookies as $k=>$v) $parts[] = "$k=$v"; $h[] = 'Cookie: '.implode('; ', $parts); }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields)); }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$code, substr($resp,0,$headerSize), substr($resp,$headerSize)];
}
function parseCookies($h) { $c=[]; foreach (explode("\n",$h) as $l) { if (stripos($l,'Set-Cookie:')!==false) { $p=trim(explode(';',substr($l,strlen('Set-Cookie:')))[0]); list($k,$v)=array_pad(explode('=',$p,2),2,''); $c[$k]=$v; } } return $c; }

function submitCase($log, $label, $payload) {
    global $BASE;
    list($code,$hdr,$body) = req("$BASE/request-visit", 'GET');
    $cookies = parseCookies($hdr);
    $xsrf = rawurldecode($cookies['XSRF-TOKEN'] ?? '');
    list($code,$hdr,$body) = req("$BASE/request-visit", 'POST', $payload, $cookies,
        ['X-XSRF-TOKEN'=>$xsrf,'Content-Type'=>'application/x-www-form-urlencoded']);
    $log .= "\n[$label] POST /request-visit -> $code\n";
    $clean = trim(strip_tags($body));
    if ($code === 302) {
        $loc = '';
        if (preg_match('/^location:\s*(.*)$/mi', $hdr, $m)) $loc = trim($m[1]);
        $log .= "  302 -> $loc\n";
        // look for flash error in session? skip; check newest visit instead
    } else {
        $log .= "  body: ".substr($clean,0,220)."\n";
    }
    return $log;
}

// Case A: co3 branch2
$log = submitCase($log, 'A: co3 br2 Business Meeting', [
    'visit_type'=>'individual','visitor_name'=>'Case A '.rand(1000,9999),'id_document_no'=>str_pad((string)rand(0,999999),6,'0',STR_PAD_LEFT),
    'visitor_email'=>'casea'.rand(1000,9999).'@test.com','visitor_phone'=>'0911'.rand(100000,999999),
    'company_id'=>3,'branch_id'=>2,'purpose'=>'Business Meeting','requested_time'=>date('Y-m-d\TH:i',strtotime('+3 days 10:30')),'duration'=>60]);

// Case B: no branch
$log = submitCase($log, 'B: co3 no-branch Business Meeting', [
    'visit_type'=>'individual','visitor_name'=>'Case B '.rand(1000,9999),'id_document_no'=>str_pad((string)rand(0,999999),6,'0',STR_PAD_LEFT),
    'visitor_email'=>'caseb'.rand(1000,9999).'@test.com','visitor_phone'=>'0911'.rand(100000,999999),
    'company_id'=>3,'purpose'=>'Business Meeting','requested_time'=>date('Y-m-d\TH:i',strtotime('+3 days 10:30')),'duration'=>60]);

// Case C: ENSA co10
$log = submitCase($log, 'C: co10 ENSA Business Meeting', [
    'visit_type'=>'individual','visitor_name'=>'Case C '.rand(1000,9999),'id_document_no'=>str_pad((string)rand(0,999999),6,'0',STR_PAD_LEFT),
    'visitor_email'=>'casec'.rand(1000,9999).'@test.com','visitor_phone'=>'0911'.rand(100000,999999),
    'company_id'=>10,'branch_id'=>4,'purpose'=>'Business Meeting','requested_time'=>date('Y-m-d\TH:i',strtotime('+3 days 10:30')),'duration'=>60]);

// Case D: no company at all
$log = submitCase($log, 'D: no company no branch Business Meeting', [
    'visit_type'=>'individual','visitor_name'=>'Case D '.rand(1000,9999),'id_document_no'=>str_pad((string)rand(0,999999),6,'0',STR_PAD_LEFT),
    'visitor_email'=>'cased'.rand(1000,9999).'@test.com','visitor_phone'=>'0911'.rand(100000,999999),
    'purpose'=>'Business Meeting','requested_time'=>date('Y-m-d\TH:i',strtotime('+3 days 10:30')),'duration'=>60]);

file_put_contents(__DIR__.'/temp_cases_result.txt', $log);
echo "done\n";
