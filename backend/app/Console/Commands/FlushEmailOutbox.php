<?php

namespace App\Console\Commands;

use App\Services\OutboxService;
use Illuminate\Console\Command;

class FlushEmailOutbox extends Command
{
    protected $signature = 'vms:flush-outbox {--limit=50}';

    protected $description = 'Retry emails queued while offline / while the SMTP gateway was unreachable';

    public function handle(): int
    {
        if (!OutboxService::online()) {
            $this->info('SMTP gateway unreachable — outbox left queued (will retry when connectivity returns).');
            return self::SUCCESS;
        }

        $result = OutboxService::flush((int) $this->option('limit'));

        $this->info("Email outbox: sent {$result['sent']}, failed {$result['failed']}, pending {$result['pending']}.");

        return self::SUCCESS;
    }
}
