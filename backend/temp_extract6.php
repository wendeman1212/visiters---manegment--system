<?php
$lines = file(__DIR__.'/../frontend/src/components/Dashboard/Host.jsx');
$total = count($lines);
$needles = ['filterVisits', 'MAIN_TABS', 'tab ===', 'badge =', "key: '", "label: '", 'status ===', 'requests', 'appointments', 'arrival', 'during', 'host_change', 'history', 'SECTIONS', 'sections', 'pending'];
$out = '';
for ($i = 0; $i < $total; $i++) {
    $t = rtrim($lines[$i]);
    $hit = false;
    foreach ($needles as $n) {
        if (strpos($t, $n) !== false) { $hit = true; break; }
    }
    if ($hit) {
        if (strlen($t) > 190) $t = substr($t, 0, 190).'..['.strlen(rtrim($lines[$i])).']';
        $out .= ($i + 1).'|'.$t.PHP_EOL;
    }
}
file_put_contents(__DIR__.'/temp_hostfilters.txt', $out);
echo 'total: '.$total.' matched lines: '.substr_count($out, "\n");
