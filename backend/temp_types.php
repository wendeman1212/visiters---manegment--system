<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->handle(Illuminate\Http\Request::create('/login', 'GET'));

use App\Models\VisitRequest;
use App\Models\User;

$v = VisitRequest::withoutGlobalScopes()->find(143);
echo "Visit #143: host_id type=".gettype($v->host_id)." value=".var_export($v->host_id, true)."\n";
echo "  alt type=".gettype($v->alternate_host_id)." value=".var_export($v->alternate_host_id, true)."\n";
echo "  escalated_to type=".gettype($v->escalated_to)." value=".var_export($v->escalated_to, true)."\n";
echo "  status=".var_export($v->status, true)."\n";

$u = User::withoutGlobalScopes()->find(12);
echo "User #12: id type=".gettype($u->id)." value=".var_export($u->id, true)."\n";

echo "strict (host_id !== user.id): ".var_export($v->host_id !== $u->id, true)."\n";
echo "loose  (host_id != user.id):  ".var_export($v->host_id != $u->id, true)."\n";

echo "\nPDO attribute check:\n";
$conn = Illuminate\Support\Facades\DB::connection()->getPdo();
echo "  ATTR_EMULATE_PREPARES: ".var_export($conn->getAttribute(PDO::ATTR_EMULATE_PREPARES), true)."\n";
echo "  driver: ".$conn->getAttribute(PDO::ATTR_DRIVER_NAME)."\n";

echo "\nVisit #151 (CEO owner): host_id type=".gettype(VisitRequest::withoutGlobalScopes()->find(151)->host_id)." value=".var_export(VisitRequest::withoutGlobalScopes()->find(151)->host_id, true)."\n";
