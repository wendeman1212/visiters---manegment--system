<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Visitor;

$v = Visitor::withoutGlobalScopes()->where('email', 'backnav@example.com')->first();
if (!$v) {
    $v = Visitor::withoutGlobalScopes()->create([
        'name'           => 'Back Nav Tester',
        'first_name'     => 'Back',
        'last_name'      => 'Nav',
        'email'          => 'backnav@example.com',
        'phone'          => '0990001122',
        'id_document_no' => '999001',
        'age'            => 29,
        'sex'            => 'male',
        'password'       => 'BackNavTest123!',
    ]);
}
echo "VISITOR_ID=" . $v->id . "\n";
echo "EMAIL=backnav@example.com\n";
echo "PASSWORD=BackNavTest123!\n";
