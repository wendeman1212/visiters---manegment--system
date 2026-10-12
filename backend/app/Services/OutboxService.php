<?php

namespace App\Services;

use App\Mail\VmsNotificationMail;
use App\Models\EmailOutbox;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Offline-safe email delivery.
 *
 * When the SMTP gateway is unreachable (no internet / gateway down) an email is
 * written to the email_outbox table instead of being dropped or, worse, being
 * reported as sent. A scheduled command (vms:flush-outbox) retries queued rows
 * with an exponential backoff once connectivity returns.
 *
 * A status is only ever 'sent' after Mail actually accepted the message.
 */
class OutboxService
{
    private const MAX_ERROR_LENGTH = 500;

    /** Backoff cap, in minutes. */
    private const MAX_BACKOFF_MINUTES = 60;

    /**
     * Queue a message for delivery. Returns the outbox row, or null when the
     * address is not a valid email (nothing is queued for garbage input).
     */
    public static function enqueue(string $email, string $title, string $message, $attachments = null, string $recipientName = ''): ?EmailOutbox
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return EmailOutbox::create([
            'to_email'        => $email,
            'to_name'         => $recipientName !== '' ? $recipientName : null,
            'subject'         => $title,
            'body'            => $message,
            'attachments'     => $attachments ? $attachments : null,
            'status'          => EmailOutbox::STATUS_QUEUED,
            'attempts'        => 0,
            'next_attempt_at' => now(),
        ]);
    }

    /** Whether the configured mailer is currently reachable. */
    public static function online(): bool
    {
        return NotificationService::smtpReachable();
    }

    /**
     * Attempt delivery of up to $limit due rows.
     *
     * @return array{sent:int, failed:int, pending:int, offline:bool}
     */
    public static function flush(int $limit = 50): array
    {
        $due = EmailOutbox::due()->orderBy('id')->limit($limit)->get();

        if ($due->isEmpty()) {
            return ['sent' => 0, 'failed' => 0, 'pending' => 0, 'offline' => false];
        }

        if (!self::online()) {
            // Gateway still unreachable — leave everything queued untouched so
            // it is retried next time. Never mark as sent.
            return ['sent' => 0, 'failed' => 0, 'pending' => $due->count(), 'offline' => true];
        }

        $sent = 0;
        $failed = 0;

        foreach ($due as $row) {
            match (self::attempt($row)) {
                EmailOutbox::STATUS_SENT   => $sent++,
                EmailOutbox::STATUS_FAILED => $failed++,
                default                    => null,
            };
        }

        return [
            'sent'    => $sent,
            'failed'  => $failed,
            'pending' => EmailOutbox::due()->count(),
            'offline' => false,
        ];
    }

    /**
     * Deliver a single outbox row and return its resulting status
     * ('sent', 'failed' or 'retry').
     */
    private static function attempt(EmailOutbox $row): string
    {
        $data = $row->attachments ? ['attachments' => $row->attachments] : [];

        try {
            Mail::to($row->to_email)->send(new VmsNotificationMail(
                $row->subject,
                $row->subject,
                $row->body,
                $row->to_name ?: 'Valued User',
                $data
            ));

            $row->forceFill([
                'status'          => EmailOutbox::STATUS_SENT,
                'sent_at'         => now(),
                'attempts'        => $row->attempts + 1,
                'last_error'      => null,
                'next_attempt_at' => null,
            ])->save();

            return EmailOutbox::STATUS_SENT;
        } catch (\Throwable $e) {
            $attempts = $row->attempts + 1;
            $exhausted = $attempts >= EmailOutbox::MAX_ATTEMPTS;

            $row->forceFill([
                'status'          => $exhausted ? EmailOutbox::STATUS_FAILED : EmailOutbox::STATUS_QUEUED,
                'attempts'        => $attempts,
                'last_error'      => mb_substr($e->getMessage(), 0, self::MAX_ERROR_LENGTH),
                'next_attempt_at' => $exhausted ? null : now()->addMinutes(min(self::MAX_BACKOFF_MINUTES, 2 ** $attempts)),
            ])->save();

            Log::warning("Email outbox delivery failed ({$row->to_email}): " . $e->getMessage());

            return $exhausted ? EmailOutbox::STATUS_FAILED : 'retry';
        }
    }
}
