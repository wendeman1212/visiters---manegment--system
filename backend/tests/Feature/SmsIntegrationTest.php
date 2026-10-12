<?php

namespace Tests\Feature;

use App\Models\SystemIntegration;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): User
    {
        return User::create([
            'name'     => 'SMS Admin',
            'email'    => 'smsadmin@test.local',
            'password' => 'password',
            'role'     => 'admin',
            'status'   => 'active',
        ]);
    }

    private function seedSmsEthiopiaConfig(string $apiKey = 'smsethiopia-test-api-key'): void
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

    public function test_admin_can_save_twilio_configuration(): void
    {
        $this->actingAs($this->createAdmin());

        $this->post('/admin/integrations/sms', [
            'provider'    => 'twilio',
            'account_sid' => 'ACabcdef0123456789abcdef01234567',
            'auth_token'  => 'secret-token-abcdef-0123456789',
            'from_number' => '+12025550123',
            'status'      => true,
        ])->assertSessionHasNoErrors();

        $integration = SystemIntegration::where('type', 'sms')->first();
        $this->assertNotNull($integration);
        $this->assertSame('active', $integration->status);

        $config = is_string($integration->config) ? json_decode($integration->config, true) : $integration->config;
        $this->assertSame('twilio', $config['provider']);
        $this->assertSame('ACabcdef0123456789abcdef01234567', $config['account_sid']);
        $this->assertSame('secret-token-abcdef-0123456789', $config['auth_token']);
        $this->assertSame('+12025550123', $config['from_number']);
    }

    public function test_saving_without_auth_token_keeps_existing_token(): void
    {
        $this->actingAs($this->createAdmin());

        SystemIntegration::create([
            'name'   => 'SMS Gateway',
            'type'   => 'sms',
            'status' => 'active',
            'config' => json_encode([
                'provider'    => 'twilio',
                'account_sid' => 'ACexistingexistingexistingexisting',
                'auth_token'  => 'stored-secret-token-123',
                'from_number' => '+12025550123',
            ]),
        ]);

        $this->post('/admin/integrations/sms', [
            'provider'    => 'twilio',
            'account_sid' => '',
            'auth_token'  => '',
            'from_number' => '+14155552671',
            'status'      => true,
        ])->assertSessionHasNoErrors();

        $config = json_decode(SystemIntegration::where('type', 'sms')->first()->config, true);
        $this->assertSame('stored-secret-token-123', $config['auth_token']);
        $this->assertSame('ACexistingexistingexistingexisting', $config['account_sid']);
        $this->assertSame('+14155552671', $config['from_number']);
    }

    public function test_twilio_requires_from_number(): void
    {
        $this->actingAs($this->createAdmin());

        $this->post('/admin/integrations/sms', [
            'provider'    => 'twilio',
            'account_sid' => 'ACabcdef0123456789abcdef01234567',
            'auth_token'  => 'some-secret-token',
            'from_number' => '',
            'status'      => true,
        ])->assertSessionHasErrors('from_number');

        $this->assertNull(SystemIntegration::where('type', 'sms')->first());
    }

    public function test_admin_dashboard_never_exposes_auth_token(): void
    {
        $this->actingAs($this->createAdmin());

        SystemIntegration::create([
            'name'   => 'SMS Gateway',
            'type'   => 'sms',
            'status' => 'active',
            'config' => json_encode([
                'provider'    => 'twilio',
                'account_sid' => 'ACabcdef0123456789abcdef01234567',
                'auth_token'  => 'super-secret-must-not-leak',
                'from_number' => '+12025550123',
            ]),
        ]);

        $this->get('/admin/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Admin')
                ->has('integrations', 1)
                ->has('integrations.0.config')
                ->missing('integrations.0.config.auth_token')
                ->where('integrations.0.config.account_sid', 'ACabcdef0123456789abcdef01234567')
            );
    }

    // ── SMSEthiopia provider ─────────────────────────────────────────────

    public function test_smsethiopia_missing_api_key_returns_skipped(): void
    {
        SystemIntegration::create([
            'name'   => 'SMS Gateway',
            'type'   => 'sms',
            'status' => 'active',
            'config' => json_encode(['provider' => 'smsethiopia']),
        ]);

        Http::fake();
        $result = SmsService::sendWithResult('0911234567', 'test');

        $this->assertSame(SmsService::STATUS_SKIPPED, $result['status']);
        Http::assertNothingSent();
    }

    public function test_smsethiopia_success_returns_sent_with_correct_request(): void
    {
        $apiKey = 'smsethiopia-test-key-123456';
        $this->seedSmsEthiopiaConfig($apiKey);

        Http::fake([
            'smsethiopia.com/*' => Http::response([
                'status'  => 'success',
                'message' => 'Accepted Successfully',
            ], 200),
        ]);

        $result = SmsService::sendWithResult('0911234567', 'Hello from VMS!');

        $this->assertSame(SmsService::STATUS_SENT, $result['status']);

        Http::assertSent(function ($request) use ($apiKey) {
            return $request->url() === 'https://smsethiopia.com/api/sms/send'
                && $request->hasHeader('KEY', $apiKey)
                && $request['msisdn'] === '251911234567'
                && $request['text'] === 'Hello from VMS!';
        });
    }

    public function test_smsethiopia_authentication_failure_returns_failed(): void
    {
        $this->seedSmsEthiopiaConfig('smsethiopia-auth-key');

        Http::fake([
            'smsethiopia.com/*' => Http::response([
                'status'  => 'error',
                'message' => 'Invalid API Key',
            ], 401),
        ]);

        $result = SmsService::sendWithResult('0911234567', 'test');

        $this->assertSame(SmsService::STATUS_FAILED, $result['status']);
        $this->assertStringContainsString('Invalid API Key', $result['reason']);
    }

    public function test_smsethiopia_http_error_returns_failed(): void
    {
        $this->seedSmsEthiopiaConfig('smsethiopia-http-key');

        Http::fake([
            'smsethiopia.com/*' => Http::response('Internal Server Error', 500),
        ]);

        $result = SmsService::sendWithResult('0911234567', 'test');

        $this->assertSame(SmsService::STATUS_FAILED, $result['status']);
    }

    public function test_smsethiopia_network_failure_returns_failed(): void
    {
        $this->seedSmsEthiopiaConfig('smsethiopia-net-key');

        Http::fake([
            'smsethiopia.com/*' => function () {
                throw new ConnectionException('cURL error 28: Operation timed out');
            },
        ]);

        $result = SmsService::sendWithResult('0911234567', 'test');

        $this->assertSame(SmsService::STATUS_FAILED, $result['status']);
        $this->assertStringContainsString('Network error', $result['reason']);
    }

    public function test_smsethiopia_explicit_error_body_is_not_treated_as_sent(): void
    {
        $this->seedSmsEthiopiaConfig('smsethiopia-body-key');

        Http::fake([
            'smsethiopia.com/*' => Http::response([
                'status'  => 'error',
                'message' => 'Insufficient balance',
            ], 200),
        ]);

        $result = SmsService::sendWithResult('0911234567', 'test');

        $this->assertSame(SmsService::STATUS_FAILED, $result['status']);
        $this->assertStringContainsString('Insufficient balance', $result['reason']);
    }

    public function test_ethiopian_phone_normalization(): void
    {
        $this->assertSame('251911234567', SmsService::normalizeEthiopianPhone('0911234567'));
        $this->assertSame('251911234567', SmsService::normalizeEthiopianPhone('+251911234567'));
        $this->assertSame('251911234567', SmsService::normalizeEthiopianPhone('251911234567'));
        $this->assertSame('251911234567', SmsService::normalizeEthiopianPhone('+251 91 123 4567'));
        $this->assertSame('251911234567', SmsService::normalizeEthiopianPhone('0911 234 567'));
        $this->assertSame('251911234567', SmsService::normalizeEthiopianPhone('911234567'));
        $this->assertNull(SmsService::normalizeEthiopianPhone('12345'));
        $this->assertNull(SmsService::normalizeEthiopianPhone(''));
    }

    public function test_smsethiopia_api_key_never_in_logs(): void
    {
        $apiKey = 'smsethiopia-super-secret-api-key-1234567890';
        $this->seedSmsEthiopiaConfig($apiKey);

        $logPath = tempnam(sys_get_temp_dir(), 'vms_log_') . '.log';
        config()->set('logging.default', 'vms_test_log');
        config()->set('logging.channels.vms_test_log', [
            'driver' => 'single',
            'path'   => $logPath,
            'level'  => 'debug',
        ]);

        Http::fake([
            'smsethiopia.com/*' => Http::response([
                'status'  => 'error',
                'message' => "Invalid API key {$apiKey}",
            ], 401),
        ]);

        $result = SmsService::sendWithResult('0911234567', 'test');

        $this->assertSame(SmsService::STATUS_FAILED, $result['status']);
        $this->assertStringNotContainsString($apiKey, $result['reason']);

        $contents = file_exists($logPath) ? (string) file_get_contents($logPath) : '';
        $this->assertStringNotContainsString($apiKey, $contents);

        @unlink($logPath);
    }

    // ── SMSEthiopia admin configuration ──────────────────────────────────

    public function test_admin_can_save_smsethiopia_configuration(): void
    {
        $this->actingAs($this->createAdmin());

        $this->post('/admin/integrations/sms', [
            'provider' => 'smsethiopia',
            'api_key'  => 'smsethiopia-saved-key-abc123',
            'status'   => true,
        ])->assertSessionHasNoErrors();

        $integration = SystemIntegration::where('type', 'sms')->first();
        $this->assertNotNull($integration);
        $this->assertSame('active', $integration->status);

        $config = json_decode($integration->config, true);
        $this->assertSame('smsethiopia', $config['provider']);
        $this->assertSame('smsethiopia-saved-key-abc123', $config['api_key']);
    }

    public function test_saving_smsethiopia_without_api_key_keeps_existing_key(): void
    {
        $this->actingAs($this->createAdmin());

        SystemIntegration::create([
            'name'   => 'SMS Gateway',
            'type'   => 'sms',
            'status' => 'active',
            'config' => json_encode([
                'provider' => 'smsethiopia',
                'api_key'  => 'smsethiopia-stored-key-xyz',
            ]),
        ]);

        $this->post('/admin/integrations/sms', [
            'provider' => 'smsethiopia',
            'api_key'  => '',
            'status'   => true,
        ])->assertSessionHasNoErrors();

        $config = json_decode(SystemIntegration::where('type', 'sms')->first()->config, true);
        $this->assertSame('smsethiopia-stored-key-xyz', $config['api_key']);
    }

    public function test_smsethiopia_requires_api_key_on_save(): void
    {
        $this->actingAs($this->createAdmin());

        $this->post('/admin/integrations/sms', [
            'provider' => 'smsethiopia',
            'api_key'  => '',
            'status'   => true,
        ])->assertSessionHasErrors('api_key');

        $this->assertNull(SystemIntegration::where('type', 'sms')->first());
    }

    public function test_admin_dashboard_never_exposes_smsethiopia_api_key(): void
    {
        $this->actingAs($this->createAdmin());

        SystemIntegration::create([
            'name'   => 'SMS Gateway',
            'type'   => 'sms',
            'status' => 'active',
            'config' => json_encode([
                'provider' => 'smsethiopia',
                'api_key'  => 'smsethiopia-super-secret-must-not-leak',
            ]),
        ]);

        $this->get('/admin/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Admin')
                ->has('integrations', 1)
                ->has('integrations.0.config')
                ->missing('integrations.0.config.api_key')
                ->missing('integrations.0.config.auth_token')
            );
    }

    // ── SMS test endpoint ────────────────────────────────────────────────

    public function test_sms_test_endpoint_reports_success(): void
    {
        $this->actingAs($this->createAdmin());
        $this->seedSmsEthiopiaConfig('smsethiopia-test-endpoint-ok');

        Http::fake([
            'smsethiopia.com/*' => Http::response([
                'status'  => 'success',
                'message' => 'Accepted Successfully',
            ], 200),
        ]);

        $this->post('/admin/integrations/sms/test', ['test_phone' => '0911234567'])
            ->assertSessionHas('success', 'Test SMS sent successfully.');
    }

    public function test_sms_test_endpoint_reports_sanitized_failure(): void
    {
        $this->actingAs($this->createAdmin());
        $apiKey = 'smsethiopia-test-endpoint-secret-key';
        $this->seedSmsEthiopiaConfig($apiKey);

        Http::fake([
            'smsethiopia.com/*' => Http::response([
                'status'  => 'error',
                'message' => "Invalid API key {$apiKey}",
            ], 401),
        ]);

        $this->post('/admin/integrations/sms/test', ['test_phone' => '0911234567'])
            ->assertSessionHas('error');

        $flash = session('error');
        $this->assertStringContainsString('Test SMS failed', (string) $flash);
        $this->assertStringContainsString('Invalid API key', (string) $flash);
        $this->assertStringNotContainsString($apiKey, (string) $flash);
    }

    public function test_sms_test_endpoint_reports_not_configured(): void
    {
        $this->actingAs($this->createAdmin());

        SystemIntegration::create([
            'name'   => 'SMS Gateway',
            'type'   => 'sms',
            'status' => 'inactive',
            'config' => json_encode(['provider' => 'smsethiopia']),
        ]);

        Http::fake();

        $this->post('/admin/integrations/sms/test', ['test_phone' => '0911234567'])
            ->assertSessionHas('error', 'SMS is not configured.');
    }

    // ── Webhook provider (existing behavior unchanged) ───────────────────

    public function test_webhook_missing_url_returns_skipped(): void
    {
        SystemIntegration::create([
            'name'   => 'SMS Gateway',
            'type'   => 'sms',
            'status' => 'active',
            'config' => json_encode(['provider' => 'webhook']),
        ]);

        $result = SmsService::sendWithResult('0911234567', 'test');

        $this->assertSame(SmsService::STATUS_SKIPPED, $result['status']);
    }

    public function test_unknown_provider_returns_skipped(): void
    {
        SystemIntegration::create([
            'name'   => 'SMS Gateway',
            'type'   => 'sms',
            'status' => 'active',
            'config' => json_encode(['provider' => 'unknown-provider']),
        ]);

        $result = SmsService::sendWithResult('0911234567', 'test');

        $this->assertSame(SmsService::STATUS_SKIPPED, $result['status']);
    }
}
