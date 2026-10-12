<?php
$lines = file(__DIR__.'/app/Http/Controllers/HostTransferController.php');
$out = '';
$total = count($lines);
for ($i = 0; $i < $total; $i++) {
    $t = rtrim($lines[$i]);
    if (strlen($t) > 220) $t = substr($t, 0, 220).'..['.strlen(rtrim($lines[$i])).']';
    $out .= ($i + 1).'|'.$t.PHP_EOL;
}
file_put_contents(__DIR__.'/temp_htc.txt', $out);
echo 'total: '.$total;
