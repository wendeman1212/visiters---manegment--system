<?php
$lines = file(__DIR__.'/../frontend/src/components/Dashboard/Host.jsx');
$total = count($lines);
$out = '';
for ($i = 0; $i < $total; $i++) {
    $t = rtrim($lines[$i]);
    if (preg_match('/filterVisits|const MAIN_TABS|tab ===|sideBar|sidebar|badge =|key: \'|label: \'|' . "'" . 'label|status ===|statuses|Requests|Appointments|Pending|Current Visit|During', $t)) {
        if (strlen($t) > 190) $t = substr($t, 0, 190).'..['.strlen(rtrim($lines[$i])).']';
        $out .= ($i + 1).'|'.$t.PHP_EOL;
    }
}
file_put_contents(__DIR__.'/temp_hostfilters.txt', $out);
echo 'total: '.$total.' matched: '.substr_count($out, "\n");
