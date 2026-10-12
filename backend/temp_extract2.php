<?php
$lines = file(__DIR__.'/app/Http/Controllers/VisitorTransferController.php');
$out = '';
for ($i = 160; $i < 315 && $i < count($lines); $i++) {
    $t = rtrim($lines[$i]);
    if (strlen($t) > 220) $t = substr($t, 0, 220).'..['.strlen(rtrim($lines[$i])).']';
    $out .= ($i + 1).'|'.$t.PHP_EOL;
}
file_put_contents(__DIR__.'/temp_vtc_store.txt', $out);
echo 'ok';
