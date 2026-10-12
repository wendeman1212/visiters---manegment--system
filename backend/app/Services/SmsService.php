<?php

namespace App\Services;

use App\Models\SystemIntegration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;

class SmsService
{
    public const STATUS_SENT    = 'sent';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    /** Messages are limited to 1600 characters (concatenated segments). */
    private const MAX_MESSAGE_LENGTH = 1600;

    /** Official SMSEthiopia REST endpoint. */
    private const SMSETHIOPIA_ENDPOINT = 'https://smsethiopia.com/api/sms/send';

    /**
     * Send an SMS through the configured gateway.
     *
     * The configuration is read from the SystemIntegration record of type "sms"
     * (Admin > System & Integrations > SMS Gateway Configuration) with an
     * optional .env fallback when the database gateway is not configured.
     *
     * Returns one of:
     *   - 'sent'    when the provider accepted the message
     *   - 'failed'  when the provider rejected the message or a network error occurred
     *   - 'skipped' when SMS is disabled or no provider is configured
     */
    public static function send(string $phone, string $message): string
    {
        return self::sendWithResult($phone, $message)['status'];
    }

    /**
     * Send an SMS and return both the status and a sanitized human-readable
     * reason so callers (e.g. the admin SMS test) can surface useful feedback
     * without ever exposing credentials.
     *
     * @return array{status: string, reason: string}
     */
    public static function sendWithResult(string $phone, string $message): array
    {
        if (empty(trim($phone))) {
            Log::warning('SMS skipped: no phone number provided.');
            return ['status' => self::STATUS_SKIPPED, 'reason' => 'No phone number provided'];
        }

        $config = self::config();

        if (empty($config['enabled'])) {
            Log::warning('SMS skipped (SMS disabled): To=' . self::maskPhone($phone) . '. Enable the SMS gateway in Admin > System & Integrations > SMS Gateway Configuration.');
            return ['status' => self::STATUS_SKIPPED, 'reason' => 'SMS gateway is disabled'];
        }

        return match ($config['provider'] ?? '') {
            'twilio'       => self::dispatchTwilio($config, $phone, $message),
            'webhook'      => self::dispatchWebhook($config, $phone, $message),
            'smsethiopia'  => self::dispatchSmsEthiopia($config, $phone, $message),
            default        => self::noProvider($config['provider'] ?? '', $phone),
        };
    }

    /**
     * Load the SMS gateway configuration.
     *
     * The SystemIntegration record (type = sms) is authoritative. Individual
     * values missing from the database fall back to the .env configuration so
     * credentials can be managed through either the Admin UI or .env securely.
     */
    public static function config(): array
    {
        $integration = SystemIntegration::where('type', 'sms')->first();

        $config = [];
        if ($integration) {
            $decoded = is_string($integration->config) ? json_decode($integration->config, true) : $integration->config;
            if (is_array($decoded)) {
                $config = $decoded;
            }
        }

        $envProvider = config('services.sms.provider', 'twilio');

        return [
            'provider'    => (string) ($config['provider'] ?? $envProvider),
            'account_sid' => (string) ($config['account_sid'] ?? config('services.twilio.account_sid', '')),
            'auth_token'  => (string) ($config['auth_token'] ?? config('services.twilio.auth_token', '')),
            'from_number' => (string) ($config['from_number'] ?? config('services.twilio.from', '')),
            'webhook_url' => (string) ($config['webhook_url'] ?? config('services.sms.webhook_url', '')),
            'api_key'     => (string) ($config['api_key'] ?? config('services.smsethiopia.api_key', '')),
            'enabled'     => $integration ? $integration->status === 'active' : (bool) config('services.sms.enabled', false),
        ];
    }

    public static function isActive(): bool
    {
        return !empty(self::config()['enabled']);
    }

    /**
     * Whether a failed send is worth retrying when connectivity returns.
     *
     * Transient (network/offline/timeout) failures are retryable; provider
     * rejections and configuration problems (invalid number, missing
     * credentials, disabled gateway) are not, so the retry queue never spins
     * forever on a permanently undeliverable message.
     */
    public static function isRetryableReason(?string $reason): bool
    {
        if (empty($reason)) {
            return true;
        }

        $reason = strtolower($reason);

        foreach ([
            'network', 'timeout', 'timed out', 'connection', 'unreachable',
            'request failed', 'no response', 'try again', 'temporarily',
        ] as $needle) {
            if (str_contains($reason, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize an Ethiopian phone number into international E.164-ish form.
     *
     *   '0911234567'      -> '251911234567'
     *   '+251911234567'   -> '251911234567'
     *   '251911234567'    -> '251911234567'
     *
     * Returns null when the number cannot be normalized to a valid Ethiopian
     * mobile/landline number (12 digits starting with 251).
     */
    public static function normalizeEthiopianPhone(string $phone): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', trim($phone));
        if ($digits === '') {
            return null;
        }

        // Already in international form.
        if (strlen($digits) === 12 && str_starts_with($digits, '251')) {
            return $digits;
        }

        // Local number with leading zero (e.g. 0911234567) -> 251911234567.
        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            return '251' . substr($digits, 1);
        }

        // Local mobile without the leading zero (e.g. 911234567) -> 251911234567.
        if (strlen($digits) === 9 && str_starts_with($digits, '9')) {
            return '251' . $digits;
        }

        return null;
    }

    private static function dispatchTwilio(array $config, string $phone, string $message): array
    {
        if (empty($config['account_sid']) || empty($config['auth_token'])) {
            Log::warning('SMS skipped (Twilio not configured): To=' . self::maskPhone($phone) . '. Add Account SID and Auth Token in Admin > System & Integrations > SMS Gateway Configuration.');
            return ['status' => self::STATUS_SKIPPED, 'reason' => 'Twilio credentials missing'];
        }

        if (empty($config['from_number'])) {
            Log::warning('SMS skipped (Twilio From Number missing): To=' . self::maskPhone($phone));
            return ['status' => self::STATUS_SKIPPED, 'reason' => 'Twilio From Number missing'];
        }

        try {
            return self::sendTwilio($config, $phone, $message);
        } catch (\Throwable $e) {
            Log::error('SMS failed to ' . self::maskPhone($phone) . ' via twilio: ' . self::sanitizeLogMessage($e->getMessage(), $config));
            return ['status' => self::STATUS_FAILED, 'reason' => 'Twilio request failed'];
        }
    }

    private static function dispatchWebhook(array $config, string $phone, string $message): array
    {
        if (empty($config['webhook_url'])) {
            Log::warning('SMS skipped (webhook not configured): To=' . self::maskPhone($phone));
            return ['status' => self::STATUS_SKIPPED, 'reason' => 'Webhook URL missing'];
        }

        try {
            return self::sendWebhook($config, $phone, $message);
        } catch (\Throwable $e) {
            Log::error('SMS failed to ' . self::maskPhone($phone) . ' via webhook: ' . $e->getMessage());
            return ['status' => self::STATUS_FAILED, 'reason' => 'Webhook request failed'];
        }
    }

    private static function dispatchSmsEthiopia(array $config, string $phone, string $message): array
    {
        if (empty($config['api_key'])) {
            Log::warning('SMS skipped (SMSEthiopia API Key missing): To=' . self::maskPhone($phone) . '. Add an API Key in Admin > System & Integrations > SMS Gateway Configuration.');
            return ['status' => self::STATUS_SKIPPED, 'reason' => 'SMSEthiopia API Key missing'];
        }

        $to = self::normalizeEthiopianPhone($phone);
        if ($to === null) {
            Log::warning('SMS failed (invalid Ethiopian phone number): To=' . self::maskPhone($phone));
            return ['status' => self::STATUS_FAILED, 'reason' => 'Invalid phone number'];
        }

        try {
            return self::sendSmsEthiopia($config, $to, $message);
        } catch (ConnectionException $e) {
            Log::error('SMS failed to ' . self::maskPhone($phone) . ' via smsethiopia (network error): ' . $e->getMessage());
            return ['status' => self::STATUS_FAILED, 'reason' => 'Network error contacting SMSEthiopia'];
        } catch (\Throwable $e) {
            Log::error('SMS failed to ' . self::maskPhone($phone) . ' via smsethiopia: ' . self::sanitizeLogMessage($e->getMessage(), $config));
            return ['status' => self::STATUS_FAILED, 'reason' => 'SMSEthiopia request failed'];
        }
    }

    private static function noProvider(string $provider, string $phone): array
    {
        Log::warning('SMS skipped (provider not configured): To=' . self::maskPhone($phone) . ", provider={$provider}.");
        return ['status' => self::STATUS_SKIPPED, 'reason' => 'SMS provider not configured'];
    }

    /**
     * Send a message through the official Twilio REST API.
     *
     * 'sent' is returned ONLY when Twilio accepts the message and returns a
     * message SID (HTTP 201). Any RestException (auth failure 401, invalid
     * number 400, insufficient balance/permissions 402/403, etc.) or a missing
     * SID is reported as 'failed'.
     */
    private static function sendTwilio(array $config, string $phone, string $message): array
    {
        $client = new Client($config['account_sid'], $config['auth_token']);

        $to   = self::normalizePhone($phone);
        $body = mb_strlen($message) > self::MAX_MESSAGE_LENGTH
            ? mb_substr($message, 0, self::MAX_MESSAGE_LENGTH)
            : $message;

        $result = $client->messages->create($to, [
            'from' => $config['from_number'],
            'body' => $body,
        ]);

        if (!empty($result->sid)) {
            Log::info('SMS accepted by Twilio to ' . self::maskPhone($phone) . ' — SID ' . $result->sid);
            return ['status' => self::STATUS_SENT, 'reason' => 'Accepted by Twilio'];
        }

        Log::error('SMS failed to ' . self::maskPhone($phone) . ': Twilio accepted the request but returned no message SID.');
        return ['status' => self::STATUS_FAILED, 'reason' => 'Twilio returned no message SID'];
    }

    /**
     * Send a message through the generic webhook provider. Kept unchanged: it
     * posts {"to", "message", "source"} as JSON and treats any 2xx response as
     * accepted.
     */
    private static function sendWebhook(array $config, string $phone, string $message): array
    {
        $url = $config['webhook_url'];
        $payload = json_encode([
            'to'      => $phone,
            'message' => $message,
            'source'  => 'vms',
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (!$curlError && $httpCode >= 200 && $httpCode < 300) {
            Log::info('SMS accepted by webhook to ' . self::maskPhone($phone) . " (HTTP {$httpCode}).");
            return ['status' => self::STATUS_SENT, 'reason' => 'Accepted by webhook'];
        }

        Log::error('SMS failed to ' . self::maskPhone($phone) . " via webhook: HTTP {$httpCode} " . ($curlError ?: 'no response body'));
        return ['status' => self::STATUS_FAILED, 'reason' => $curlError ?: "Webhook responded with HTTP {$httpCode}"];
    }

    /**
     * Send a message through the official SMSEthiopia REST API.
     *
     * The API key is sent in the KEY header. 'sent' is returned ONLY when the
     * provider responds with a success status (e.g. {"status":"success"}). An
     * HTTP error, an authentication failure, a provider rejection in the body,
     * or a malformed response is reported as 'failed'.
     */
    private static function sendSmsEthiopia(array $config, string $to, string $message): array
    {
        $body = mb_strlen($message) > self::MAX_MESSAGE_LENGTH
            ? mb_substr($message, 0, self::MAX_MESSAGE_LENGTH)
            : $message;

        $response = Http::withHeaders([
            'KEY'           => $config['api_key'],
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ])->withOptions([
            'timeout' => 30,
        ])->post(self::SMSETHIOPIA_ENDPOINT, [
            'msisdn' => $to,
            'text'   => $body,
        ]);

        $payload = $response->json();

        if ($response->successful() && self::smsEthiopiaResponseIsSuccess($payload)) {
            Log::info('SMS accepted by SMSEthiopia to ' . self::maskPhone($to));
            return ['status' => self::STATUS_SENT, 'reason' => 'Accepted by SMSEthiopia'];
        }

        $providerMessage = is_array($payload) ? (string) ($payload['message'] ?? $payload['status'] ?? '') : '';
        $reason = trim(self::sanitizeLogMessage($providerMessage, $config));
        if ($reason === '') {
            $reason = 'SMSEthiopia responded with HTTP ' . $response->status();
        }

        Log::error('SMS failed to ' . self::maskPhone($to) . " via smsethiopia: HTTP {$response->status()} " . $reason);
        return ['status' => self::STATUS_FAILED, 'reason' => $reason];
    }

    /**
     * Decide whether an SMSEthiopia JSON payload represents an accepted message.
     * An HTTP 2xx is NOT sufficient on its own: a payload that explicitly
     * reports an error is treated as a failure.
     */
    private static function smsEthiopiaResponseIsSuccess($payload): bool
    {
        if (!is_array($payload)) {
            return false;
        }

        $status = strtolower(trim((string) ($payload['status'] ?? '')));

        return in_array($status, ['success', 'sent', 'ok', 'accepted', 'delivered'], true);
    }

    /**
     * Normalize a phone number for the Twilio API (E.164 or country format).
     */
    private static function normalizePhone(string $phone): string
    {
        return preg_replace('/[^0-9+]/', '', trim($phone));
    }

    /**
     * Mask a phone number before logging so full PII is never written to logs.
     */
    private static function maskPhone(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);
        $length = strlen($digits);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return substr($digits, 0, 3) . str_repeat('*', max(0, $length - 7)) . substr($digits, -4);
    }

    /**
     * Redact any stored secret (auth token, account SID, SMSEthiopia API key)
     * that may have been echoed back inside an exception or provider message
     * before it is written to the logs.
     */
    private static function sanitizeLogMessage(string $message, array $config): string
    {
        foreach ([$config['auth_token'] ?? '', $config['account_sid'] ?? '', $config['api_key'] ?? ''] as $secret) {
            if ($secret !== '') {
                $message = str_replace($secret, '[REDACTED]', $message);
            }
        }

        // Belt-and-suspenders: redact any 32+ character base64-looking token.
        $message = preg_replace('~[A-Za-z0-9+/=_-]{32,}~', '[REDACTED]', $message);

        return $message ?? '';
    }
}
