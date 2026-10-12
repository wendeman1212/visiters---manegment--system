<?php
$lines = file(__DIR__.'/app/Http/Controllers/VisitorTransferController.php');
$out = '';
for ($i = 0; $i < 110 && $i < count($lines); $i++) {
    $t = rtrim($lines[$i]);
    if (strlen($t) > 200) $t = substr($t, 0, 200).'..['.strlen(rtrim($lines[$i])).']';
    $out .= ($i + 1).'|'.$t.PHP_EOL;
}
file_put_contents(__DIR__.'/temp_vtc.txt', $out);
echo 'ok';
