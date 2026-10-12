<?php

namespace App\Console\Commands;

use App\Models\SmsSync;
use App\Services\SmsService;
use App\Services\SmsSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RetrySmsQueue extends Command
{
    protected $signature = 'vms:retry-sms {--limit=50}';

    protected $description = 'Retry SMS messages queued while the gateway was offline / unreachable';

    public function handle(): int
    {
        if (!SmsService::isActive()) {
            $this->info('SMS gateway inactive — nothing to retry.');
            return self::SUCCESS;
        }

        $rows = SmsSync::due()->orderBy('id')->limit((int) $this->option('limit'))->get();

        if ($rows->isEmpty()) {
            $this->info('SMS queue: nothing due.');
            return self::SUCCESS;
        }

        $service = new SmsSyncService();
        $sent = 0;
        $failed = 0;
        $pending = 0;

        foreach ($rows as $row) {
            $message = $row->message
                ?: $service->messageFor($row->event, $row->recipient_type, null, $row->phone);

            $result = SmsService::sendWithResult($row->phone, $message);

            $attempts = $row->attempts + 1;
            $retryable = $result['status'] === SmsService::STATUS_FAILED
                && SmsService::isRetryableReason($result['reason'] ?? '');
            $exhausted = $attempts >= SmsSync::MAX_ATTEMPTS;

            $row->update([
                'status'          => $result['status'],
                'reason'          => $result['reason'] ?? null,
                'attempts'        => $attempts,
                'last_attempt_at' => now(),
                'next_attempt_at' => ($retryable && !$exhausted)
                    ? now()->addMinutes(min(60, 2 ** $attempts))
                    : null,
            ]);

            match (true) {
                $result['status'] === SmsService::STATUS_SENT => $sent++,
                $exhausted                                    => $failed++,
                default                                       => $pending++,
            };

            Log::info("SMS queue retry #{$attempts} for {$row->phone}: {$result['status']}.");
        }

        $this->info("SMS queue: sent {$sent}, exhausted {$failed}, still pending {$pending}.");

        return self::SUCCESS;
    }
}
