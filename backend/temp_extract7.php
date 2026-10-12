<?php
$lines = file(__DIR__.'/../frontend/src/components/Dashboard/Host.jsx');
$out = '';
for ($i = 204; $i < 246 && $i < count($lines); $i++) {
    $out .= ($i + 1).'|'.rtrim($lines[$i]).PHP_EOL;
}
$out .= "---- APPOINTMENTS TAB ----".PHP_EOL;
for ($i = 895; $i < 960 && $i < count($lines); $i++) {
    $t = rtrim($lines[$i]);
    if (strlen($t) > 200) $t = substr($t, 0, 200).'..['.strlen(rtrim($lines[$i])).']';
    $out .= ($i + 1).'|'.$t.PHP_EOL;
}
file_put_contents(__DIR__.'/temp_sections.txt', $out);
echo 'ok';
