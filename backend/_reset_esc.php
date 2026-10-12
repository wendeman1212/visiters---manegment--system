<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$v = DB::table('visit_requests')->find(84);
$v = (array) $v;
$v['host_id'] = 24;
$v['escalated_to'] = 24;
$v['status'] = 'escalated';
$v['updated_at'] = DB::raw('NOW()');
DB::table('visit_requests')->where('id', 84)->update($v);

$e = DB::table('escalations')->where('visit_request_id', 84)->orderBy('id', 'desc')->first();
DB::table('escalations')->where('id', $e->id)->update(['resolved_at' => null, 'updated_at' => DB::raw('NOW()')]);

$v2 = DB::table('visit_requests')->find(84);
echo "Re-escalated visit 84: host={$v2->host_id} esc_to={$v2->escalated_to} status={$v2->status}\n";
