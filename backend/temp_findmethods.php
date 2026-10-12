<?php
$lines = file(__DIR__.'/app/Http/Controllers/VisitorTransferController.php');
foreach ($lines as $i => $l) {
    if (preg_match('/function (store|options|actorOrAbort|buildOptions|logTransfer|sendNotifications)/', $l, $m)) {
        echo ($i + 1).': '.trim($l).PHP_EOL;
    }
}
