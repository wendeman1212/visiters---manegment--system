<?php

namespace App\Services;

use App\Models\SmsSync;
use App\Models\User;
use App\Models\VisitRequest;
use Illuminate\Support\Facades\Log;

class SmsSyncService
{
    public const EVENT_REQUESTED  = 'visit_requested';
    public const EVENT_APPROVED   = 'visit_approved';
    public const EVENT_CHECKED_IN = 'visit_checked_in';
    public const EVENT_ESCALATED_TO_CEO = 'visit_escalated_to_ceo';

    /**
     * Retrieve the visitor/host phone numbers for a visit and sync them to the
     * SMS Gateway queue so they are ready for dispatch.
     */
    public static function syncVisit(VisitRequest $visit, string $event): void
    {
        $service = new self();

        $service->syncPhone($event, $visit->id, $visit->company_id, 'visitor', $visit->visitor?->id, $visit->visitor?->phone, $visit->visitor?->name);
        $service->syncPhone($event, $visit->id, $visit->company_id, 'host', $visit->host?->id, $visit->host?->phone, $visit->host?->name);
    }

    /**
     * Same as syncVisit() but the whole SMS sync (queue row + gateway send) is
     * deferred until after the HTTP response has been sent, so a slow or
     * unreachable SMS gateway never blocks the guard's request. Messages are
     * still dispatched for every recipient exactly as before.
     */
    public static function syncVisitDeferred(VisitRequest $visit, string $event): void
    {
        app()->terminating(function () use ($visit, $event) {
            self::syncVisit($visit, $event);
        });
    }

    /**
     * Sync an event-driven SMS to a specific user (by role within a company).
     * Used for Admin/CEO escalation notifications that aren't tied to a
     * visitor/host on the visit record itself.
     */
    public static function syncUserDeferred(User $user, VisitRequest $visit, string $event): void
    {
        app()->terminating(function () use ($user, $visit, $event) {
            (new self())->syncPhone($event, $visit->id, $user->company_id, $user->role, $user->id, $user->phone, $user->name);
        });
    }

    /**
     * Write one phone number into the SMS Gateway queue and attempt delivery.
     * A missing phone is skipped silently so lookups are never blocking.
     */
    public function syncPhone(string $event, ?int $visitRequestId, ?int $companyId, string $recipientType, ?int $recipientId, ?string $phone, ?string $recipientName = null): void
    {
        if (empty(trim((string) $phone))) {
            return;
        }

        $message = $this->messageFor($event, $recipientType, $recipientName, $phone);

        $sync = SmsSync::create([
            'visit_request_id' => $visitRequestId,
            'recipient_type'   => $recipientType,
            'recipient_id'     => $recipientId,
            'phone'            => $phone,
            'event'            => $event,
            'message'          => $message,
            'status'           => 'pending',
            'attempts'         => 0,
            'next_attempt_at'  => now(),
            'company_id'       => $companyId,
        ]);

        try {
            $result = SmsService::sendWithResult($phone, $message);
            $this->recordAttempt($sync, $result);
        } catch (\Throwable $e) {
            Log::error("SMS sync failed for event {$event} to {$phone}: " . $e->getMessage());
            $this->recordAttempt($sync, [
                'status' => SmsService::STATUS_FAILED,
                'reason' => 'Exception: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Persist the outcome of one send attempt and, when the failure is
     * transient (offline / gateway unreachable), schedule a retry so the
     * message is delivered once connectivity returns. Permanent failures are
     * left with no next attempt so the retry queue does not loop.
     */
    private function recordAttempt(SmsSync $sync, array $result): void
    {
        $attempts = $sync->attempts + 1;
        $retryable = $result['status'] === SmsService::STATUS_FAILED
            && SmsService::isRetryableReason($result['reason'] ?? '');
        $exhausted = $attempts >= SmsSync::MAX_ATTEMPTS;

        $sync->update([
            'status'          => $result['status'],
            'reason'          => $result['reason'] ?? null,
            'attempts'        => $attempts,
            'last_attempt_at' => now(),
            'next_attempt_at' => ($retryable && !$exhausted)
                ? now()->addMinutes(min(60, 2 ** $attempts))
                : null,
        ]);
    }

    /**
     * Short gateway message per event/recipient, prefixed with the recipient's
     * name, role and phone number as requested by the text-message format.
     */
    public function messageFor(string $event, string $recipientType, ?string $recipientName = null, ?string $phone = null): string
    {
        $role = match ($recipientType) {
            'visitor' => 'Visitor',
            'host'    => 'Host',
            default   => ucfirst($recipientType),
        };

        $greeting = 'Dear ' . ($recipientName ?: $role) . " ({$role}), Phone: " . ($phone ?: 'N/A') . ". ";

        $messages = [
            self::EVENT_REQUESTED => [
                'visitor' => 'Your visit request has been received and is awaiting host approval.',
                'host'    => 'A new visit request is awaiting your approval. Please review it from your dashboard.',
            ],
            self::EVENT_APPROVED => [
                'visitor' => 'Your visit request has been approved. Please present your QR code at the security gate.',
                'host'    => 'Your visitor has been approved. Please expect them at the scheduled time.',
            ],
            self::EVENT_CHECKED_IN => [
                'visitor' => 'You have been checked in at the security gate. Welcome!',
                'host'    => 'Your visitor has arrived and checked in at the security gate.',
            ],
            self::EVENT_ESCALATED_TO_CEO => [
                'admin' => 'A visit request that was escalated to you has exceeded the SLA timeout and has been re-escalated to the CEO for further review.',
                'ceo'   => 'A visit request has been re-escalated to you after the Admin exceeded the SLA timeout. Please review it immediately from your dashboard.',
            ],
        ];

        return $greeting . ($messages[$event][$recipientType] ?? 'VMS notification.');
    }
}
