<?php

use Illuminate\Support\Facades\Schedule;

// Auto-process SLA escalations every minute
Schedule::command('vms:process-sla')->everyMinute();

// Clean up used/expired QR codes every 15 minutes
Schedule::command('vms:cleanup-qr')->everyFifteenMinutes();

// Offline sync: deliver messages queued while the server had no internet.
// Both commands no-op when the respective gateway is not configured and only
// mark a message sent once the provider actually accepts it.
Schedule::command('vms:retry-sms')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('vms:flush-outbox')->everyFiveMinutes()->withoutOverlapping();
