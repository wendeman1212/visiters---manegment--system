<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\VisitRequest;
use App\Models\Visitor;
use App\Models\User;

$BASE = 'http://127.0.0.1:3255';
$log = '';

function req($url, $method='GET', $fields=[], $cookies=[], $headers=[], $jar=null) {
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_URL=>$url, CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_HEADER=>true]);
    $h = ['Accept: application/json'];
    foreach ($headers as $k=>$v) $h[] = "$k: $v";
    if ($cookies) { $parts=[]; foreach ($cookies as $k=>$v) $parts[]="$k=$v"; $h[]='Cookie: '.implode('; ',$parts); }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    if ($method==='POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields)); }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$code, substr($resp,0,$headerSize), substr($resp,$headerSize)];
}
function parseCookies($h){ $c=[]; foreach(explode("\n",$h) as $l){ if(stripos($l,'Set-Cookie:')!==false){ $p=trim(explode(';',substr($l,strlen('Set-Cookie:')))[0]); list($k,$v)=array_pad(explode('=',$p,2),2,''); $c[$k]=$v; } } return $c; }

// submit co3 branch2 (previously FAILED with "No active host...")
$email = 'br2test'.rand(1000,9999).'@test.com';
list($code,$hdr,$body) = req("$BASE/request-visit", 'GET');
$cookies = parseCookies($hdr);
$xsrf = rawurldecode($cookies['XSRF-TOKEN'] ?? '');
list($code,$hdr,$body) = req("$BASE/request-visit", 'POST', [
    'visit_type'=>'individual','visitor_name'=>'Branch2 Test','id_document_no'=>str_pad((string)rand(0,999999),6,'0',STR_PAD_LEFT),
    'visitor_email'=>$email,'visitor_phone'=>'0911'.rand(100000,999999),
    'company_id'=>3,'branch_id'=>2,'purpose'=>'Business Meeting','requested_time'=>date('Y-m-d\TH:i',strtotime('+3 days 10:30')),'duration'=>60],
    $cookies, ['X-XSRF-TOKEN'=>$xsrf,'Content-Type'=>'application/x-www-form-urlencoded']);
$log .= "POST /request-visit (co3 br2) -> {$code}\n";

$visitor = Visitor::withoutGlobalScopes()->where('email',$email)->first();
if (!$visitor) { $log .= "NO VISITOR CREATED — request still fails\n"; file_put_contents(__DIR__.'/temp_br2_result.txt',$log); exit; }
$visit = VisitRequest::withoutGlobalScopes()->where('visitor_id',$visitor->id)->orderByDesc('id')->first();
$host = $visit ? User::withoutGlobalScopes()->find($visit->host_id) : null;
$log .= "visit #".($visit?->id)." status=".($visit?->status)." host=".($visit?->host_id)."(".($host?->name).",br=".($host?->branch_id??'NULL').") req_br=".($visit?->branch_id??'NULL')." req_co=".($visit?->company_id??'NULL')."\n";

// login as assigned host, check dashboard
list($code,$hdr,$body) = req("$BASE/login", 'GET');
$cookies = parseCookies($hdr);
$xsrf = rawurldecode($cookies['XSRF-TOKEN'] ?? '');
list($code,$hdr,$body) = req("$BASE/login", 'POST', ['email'=>$host->email,'password'=>'password123','remember'=>'0'],
    $cookies, ['X-XSRF-TOKEN'=>$xsrf,'Content-Type'=>'application/x-www-form-urlencoded']);
$cookies = array_merge($cookies, parseCookies($hdr));
$log .= "login as {$host->email} -> {$code}\n";
list($code,$hdr,$body) = req("$BASE/host/dashboard", 'GET', [], $cookies, []);
if (preg_match('/data-page="app" type="application\/json">(.*?)<\/script>/s', $body, $m)) {
    $page = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
    $visits = $page['props']['visits'] ?? [];
    $ids = array_column($visits, 'id');
    $log .= "assigned-host dashboard visits=".count($visits)." | visit#{$visit->id} visible: ".(in_array($visit->id,$ids)?'YES':'NO')."\n";
} else {
    $log .= "no dashboard data-page (code $code)\n";
}

file_put_contents(__DIR__.'/temp_br2_result.txt', $log);
echo "done\n";
