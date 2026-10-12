<?php
$lines = file(__DIR__.'/../frontend/src/components/Dashboard/VisitorTransferModal.jsx');
$out = '';
$total = count($lines);
for ($i = 0; $i < $total; $i++) {
    $t = rtrim($lines[$i]);
    if (strlen($t) > 200) $t = substr($t, 0, 200).'..['.strlen(rtrim($lines[$i])).']';
    $out .= ($i + 1).'|'.$t.PHP_EOL;
}
file_put_contents(__DIR__.'/temp_modal.txt', $out);
echo 'total lines: '.$total;
