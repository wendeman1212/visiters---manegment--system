<?php

namespace App\Console\Commands;

use App\Services\ApprovalService;
use Illuminate\Console\Command;

class ProcessSlaEscalations extends Command
{
    protected $signature = 'vms:process-sla';
    protected $description = 'Auto-escalate pending visit requests that have exceeded the SLA timeout';

    public function handle(): int
    {
        $count = ApprovalService::processAutoEscalations();

        if ($count > 0) {
            $this->info("SLA processed: auto-escalated {$count} pending request(s).");
        } else {
            $this->info("SLA processed: no requests needed escalation.");
        }

        return 0;
    }
}
