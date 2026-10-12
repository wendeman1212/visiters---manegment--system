<?php

namespace Tests\Feature;

use App\Mail\VmsNotificationMail;
use App\Models\EmailOutbox;
use App\Models\SmsSync;
use App\Models\SystemIntegration;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\OutboxService;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Offline behaviour: when the SMTP / SMS gateways cannot be reached everything
 * must be queued and retried later — never dropped and never falsely reported
 * as sent.
 */
class OfflineSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetNotificationStatics();
    }

    protected function tearDown(): void
    {
        $this->resetNotificationStatics();
        parent::tearDown();
    }

    /**
     * NotificationService caches the SMTP pre-flight result and the deferred
     * delivery deadline in static properties that normally reset at the end of
     * each HTTP request. PHPUnit runs many "requests" in one process, so reset
     * them here to keep tests independent.
     */
    private function resetNotificationStatics(): void
    {
        foreach (['smtpReachableCache', 'deferredDeadline'] as $property) {
            $ref = new \ReflectionProperty(NotificationService::class, $property);
            $ref->setAccessible(true);
            $ref->setValue(null, null);
        }
    }

    /** Point the mailer at a closed port so the pre-flight always fails. */
    private function forceSmtpUnreachable(): void
    {
        config([
            'mail.default'                => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.host'      => '127.0.0.1',
            'mail.mailers.smtp.port'      => 1,
        ]);
        $this->resetNotificationStatics();
    }

    private function useLocalMailer(): void
    {
        config([
            'mail.default'                => 'array',
            'mail.mailers.array.transport' => 'array',
        ]);
        $this->resetNotificationStatics();
    }

    private function user(string $email = 'recipient@test.local'): User
    {
        return User::create([
            'name'     => 'Offline User',
            'email'    => $email,
            'password' => 'password',
            'role'     => 'admin',
            'status'   => 'active',
        ]);
    }

    private function seedSmsEthiopia(string $apiKey = 'offline-test-key'): void
    {
        SystemIntegration::create([
            'name'   => 'SMS Gateway',
            'type'   => 'sms',
            'status' => 'active',
            'config' => json_encode([
                'provider' => 'smsethiopia',
                'api_key'  => $apiKey,
            ]),
        ]);
    }

    // ---------------------------------------------------------------------
    // Retryability classification
    // ---------------------------------------------------------------------

    public function test_transient_failures_are_retryable(): void
    {
        $this->assertTrue(SmsService::isRetryableReason('Network error: cURL error 28: Operation timed out'));
        $this->assertTrue(SmsService::isRetryableReason('Connection refused'));
        $this->assertTrue(SmsService::isRetryableReason('Gateway unreachable'));
        $this->assertTrue(SmsService::isRetryableReason(null));
    }

    public function test_permanent_failures_are_not_retryable(): void
    {
        $this->assertFalse(SmsService::isRetryableReason('Invalid API Key'));
        $this->assertFalse(SmsService::isRetryableReason('Insufficient balance'));
        $this->assertFalse(SmsService::isRetryableReason('Invalid phone number'));
    }

    // ---------------------------------------------------------------------
    // Email outbox
    // ---------------------------------------------------------------------

    public function test_email_queues_instead_of_dropping_when_smtp_unreachable(): void
    {
        $this->forceSmtpUnreachable();

        $user = $this->user('owner@test.local');
        $notif = NotificationService::send('Offline notice', $user, 'Body text');

        $this->assertSame('queued', $notif->data['email_status']);
        $this->assertDatabaseHas('email_outbox', [
            'to_email' => 'owner@test.local',
            'status'   => EmailOutbox::STATUS_QUEUED,
        ]);
    }

    public function test_email_reports_sent_when_gateway_reachable(): void
    {
        Mail::fake();
        $this->useLocalMailer();

        $user = $this->user('owner@test.local');
        $notif = NotificationService::send('Deliverable', $user, 'Body text');

        $this->assertSame('sent', $notif->data['email_status']);
        $this->assertDatabaseCount('email_outbox', 0);
        Mail::assertSent(VmsNotificationMail::class);
    }

    public function test_outbox_flush_is_a_noop_while_offline_and_marks_nothing_sent(): void
    {
        $this->forceSmtpUnreachable();
        OutboxService::enqueue('a@test.local', 'Subject', 'Body');

        $result = OutboxService::flush();

        $this->assertSame(0, $result['sent']);
        $this->assertTrue($result['offline']);
        $this->assertSame(1, $result['pending']);
        $this->assertDatabaseHas('email_outbox', [
            'to_email' => 'a@test.local',
            'status'   => EmailOutbox::STATUS_QUEUED,
            'attempts' => 0,
        ]);
    }

    public function test_outbox_flush_delivers_queued_rows_once_online(): void
    {
        Mail::fake();
        $this->useLocalMailer();

        OutboxService::enqueue('a@test.local', 'Subject', 'Body', null, 'Ann');
        $result = OutboxService::flush();

        $this->assertSame(1, $result['sent']);
        $this->assertFalse($result['offline']);
        $this->assertDatabaseHas('email_outbox', [
            'to_email' => 'a@test.local',
            'status'   => EmailOutbox::STATUS_SENT,
        ]);
        Mail::assertSent(VmsNotificationMail::class);
    }

    public function test_outbox_ignores_malformed_addresses(): void
    {
        $this->assertNull(OutboxService::enqueue('not-an-email', 'Subject', 'Body'));
        $this->assertDatabaseCount('email_outbox', 0);
    }

    public function test_flush_email_outbox_command_reports_offline_and_keeps_rows(): void
    {
        $this->forceSmtpUnreachable();
        OutboxService::enqueue('keep@test.local', 'Subject', 'Body');

        $this->artisan('vms:flush-outbox')->assertExitCode(0);

        $this->assertDatabaseHas('email_outbox', [
            'to_email' => 'keep@test.local',
            'status'   => EmailOutbox::STATUS_QUEUED,
        ]);
    }

    // ---------------------------------------------------------------------
    // SMS retry queue
    // ---------------------------------------------------------------------

    private function queuedSms(array $overrides = []): SmsSync
    {
        return SmsSync::create(array_merge([
            'phone'           => '0911234567',
            'event'           => 'visit_requested',
            'message'         => 'Hello from VMS',
            'status'          => SmsService::STATUS_FAILED,
            'reason'          => 'Network error: cURL error 28',
            'attempts'        => 1,
            'next_attempt_at' => now()->subMinute(),
        ], $overrides));
    }

    public function test_sms_retry_delivers_due_message_when_gateway_recovers(): void
    {
        $this->seedSmsEthiopia();

        Http::fake([
            'smsethiopia.com/*' => Http::response(['status' => 'success', 'message' => 'Accepted'], 200),
        ]);

        $sms = $this->queuedSms();

        $this->artisan('vms:retry-sms')->assertExitCode(0);

        $sms->refresh();
        $this->assertSame(SmsService::STATUS_SENT, $sms->status);
        $this->assertSame(2, $sms->attempts);
        $this->assertNull($sms->next_attempt_at);
    }

    public function test_sms_retry_reschedules_a_transient_failure(): void
    {
        $this->seedSmsEthiopia();

        Http::fake([
            'smsethiopia.com/*' => function () {
                throw new ConnectionException('cURL error 28: Operation timed out');
            },
        ]);

        $sms = $this->queuedSms();

        $this->artisan('vms:retry-sms')->assertExitCode(0);

        $sms->refresh();
        $this->assertSame(SmsService::STATUS_FAILED, $sms->status);
        $this->assertSame(2, $sms->attempts);
        $this->assertNotNull($sms->next_attempt_at);
        $this->assertTrue($sms->next_attempt_at->isFuture());

        // Because the backoff has not elapsed yet, a second run picks up nothing.
        $this->assertSame(0, SmsSync::due()->count());
    }

    public function test_sms_retry_does_not_requeue_a_permanent_failure(): void
    {
        $this->seedSmsEthiopia();

        Http::fake([
            'smsethiopia.com/*' => Http::response(['status' => 'error', 'message' => 'Invalid API Key'], 401),
        ]);

        $sms = $this->queuedSms();

        $this->artisan('vms:retry-sms')->assertExitCode(0);

        $sms->refresh();
        $this->assertSame(SmsService::STATUS_FAILED, $sms->status);
        $this->assertNull($sms->next_attempt_at);
        $this->assertSame(0, SmsSync::due()->count());
    }

    public function test_sms_due_scope_ignores_future_and_exhausted_rows(): void
    {
        $this->queuedSms(['next_attempt_at' => now()->addHour()]);
        $this->queuedSms(['attempts' => SmsSync::MAX_ATTEMPTS, 'next_attempt_at' => now()->subMinute()]);
        $this->queuedSms(['status' => SmsService::STATUS_SENT, 'next_attempt_at' => now()->subMinute()]);
        $due = $this->queuedSms(['next_attempt_at' => now()->subMinute()]);

        $ids = SmsSync::due()->pluck('id')->all();

        $this->assertSame([$due->id], $ids);
    }
}
