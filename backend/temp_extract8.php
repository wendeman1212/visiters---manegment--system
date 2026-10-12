<?php
$lines = file(__DIR__.'/../frontend/src/components/Dashboard/Host.jsx');
$out = '';
for ($i = 118; $i < 175 && $i < count($lines); $i++) {
    $t = rtrim($lines[$i]);
    if (strlen($t) > 190) $t = substr($t, 0, 190).'..['.strlen(rtrim($lines[$i])).']';
    $out .= ($i + 1).'|'.$t.PHP_EOL;
}
file_put_contents(__DIR__.'/temp_tabinit.txt', $out);
echo 'ok';
