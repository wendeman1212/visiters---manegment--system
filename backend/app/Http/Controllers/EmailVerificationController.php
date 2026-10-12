<?php

namespace App\Http\Controllers;

use App\Notifications\EmailVerificationNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

/**
 * Real-world email verification for the registration flow.
 *
 * A 6-digit code is emailed to the visitor before their account can be
 * created. The code is stored (SHA-256 hashed) in the cache with a 15-minute
 * expiry and a wrong-attempt budget. Successful verification issues a
 * single-use "proof" token bound to the email; AuthController::register()
 * consumes that proof right before creating the account, so an already-used
 * or expired proof can never be replayed.
 *
 * Delivery reuses the existing notification/mail infrastructure
 * (EmailVerificationNotification -> MailMessage -> default mailer).
 */
class EmailVerificationController extends Controller
{
    /** How long a verification code stays valid. */
    private const TTL_MINUTES = 15;

    /** Cooldown before a new code can be requested (resend). */
    private const RESEND_SECONDS = 60;

    /** Wrong codes allowed per issued code before it is invalidated. */
    private const MAX_ATTEMPTS = 5;

    /** Verification-code sends per rate-limit window. */
    private const MAX_SENDS = 5;

    /** How long the single-use proof stays valid after verification. */
    private const PROOF_TTL_MINUTES = 30;

    /**
     * Send a verification code to the given email.
     */
    public function send(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|max:255',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $email = $this->normalizeEmail($request->email);

        if (!$this->rateLimit('send', $email, $request->ip())) {
            return back()->withErrors(['email' => 'Too many verification requests. Please wait a few minutes and try again.'])->withInput();
        }

        $key = $this->otpKey($email);
        $existing = Cache::get($key);

        if ($existing && ($existing['last_sent_at'] ?? 0) && (now()->getTimestamp() - $existing['last_sent_at']) < self::RESEND_SECONDS) {
            return back()
                ->withErrors(['email' => 'Please wait a moment before requesting another code.'])
                ->with('cooldown_remaining', $this->remainingSeconds($existing));
        }

        $sent = $this->issueCode($email, $key);
        if (! $sent) {
            return back()->withErrors(['email' => 'We could not send the verification code right now. Please try again in a few moments.'])->withInput();
        }

        session(['pending_verify_email' => $email]);

        return redirect()->route('verify-email')
            ->with('success', 'A verification code has been sent to your email. Enter it on the next page to verify your address.');
    }

    /**
     * Resend a fresh verification code to the email used for this flow.
     */
    public function resend(Request $request)
    {
        $email = $this->resolveEmail($request);
        if (!$email) {
            return redirect()->route('verify-email')->withErrors(['email' => 'Please enter your email address first.']);
        }

        $key = $this->otpKey($email);
        $existing = Cache::get($key);

        if ($existing && ($existing['last_sent_at'] ?? 0) && (now()->getTimestamp() - $existing['last_sent_at']) < self::RESEND_SECONDS) {
            return redirect()->route('verify-email')->withErrors(['code' => 'Please wait a moment before requesting another code.']);
        }

        if (!$this->rateLimit('send', $email, $request->ip())) {
            return redirect()->route('verify-email')->withErrors(['email' => 'Too many verification requests. Please wait a few minutes and try again.']);
        }

        $sent = $this->issueCode($email, $key);
        if (! $sent) {
            return redirect()->route('verify-email')->withErrors(['email' => 'We could not send the verification code right now. Please try again in a few moments.'])->withInput();
        }

        session(['pending_verify_email' => $email]);

        return redirect()->route('verify-email')
            ->with('success', 'A new verification code has been sent to your email.');
    }

    /**
     * Check a submitted code. On success, issues a single-use proof token that
     * the registration step consumes.
     */
    public function verify(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|max:255',
            'code'  => 'required|digits:6',
        ]);

        if ($validator->fails()) {
            return redirect()->route('verify-email')->withErrors($validator)->withInput();
        }

        $email = $this->normalizeEmail($request->email);
        $key = $this->otpKey($email);
        $payload = Cache::get($key);

        if (!$payload) {
            return redirect()->route('verify-email')->withErrors(['code' => 'Invalid or expired verification code. Please request a new code.']);
        }

        if (!hash_equals($payload['code_hash'], hash('sha256', $request->code))) {
            $payload['attempts'] = ($payload['attempts'] ?? 0) + 1;

            if ($payload['attempts'] >= self::MAX_ATTEMPTS) {
                Cache::forget($key);
                return redirect()->route('verify-email')->withErrors(['code' => 'Too many incorrect attempts. Please request a new code.']);
            }

            Cache::put($key, $payload, now()->addMinutes(self::TTL_MINUTES));
            return redirect()->route('verify-email')->withErrors(['code' => 'The verification code is incorrect. Please check and try again.']);
        }

        Cache::forget($key);

        $token = bin2hex(random_bytes(32));
        Cache::put($this->proofKey($token), ['email' => $email], now()->addMinutes(self::PROOF_TTL_MINUTES));

        session([
            'email_verified_token' => $token,
            'pending_verify_email' => $email,
        ]);

        return redirect()->route('register')
            ->with('success', 'Your email has been verified. You can now complete your registration.');
    }

    /**
     * Consume a previously issued single-use proof for the given email.
     * Returns false when no valid proof exists (not verified, expired, or
     * already used).
     */
    public function consumeProofFor(?string $email): bool
    {
        $token = session('email_verified_token');
        if (!$token) {
            return false;
        }

        $proof = Cache::get($this->proofKey($token));
        if (!$proof || strtolower($proof['email']) !== strtolower($this->normalizeEmail($email))) {
            return false;
        }

        Cache::forget($this->proofKey($token));
        session()->forget('email_verified_token');

        return true;
    }

    /**
     * Whether a valid, unconsumed proof currently exists for the email.
     * Used to pre-render the "verified" state when the register page loads.
     */
    public function hasProofFor(?string $email): bool
    {
        if (!$email) {
            return false;
        }

        $token = session('email_verified_token');
        if (!$token) {
            return false;
        }

        $proof = Cache::get($this->proofKey($token));
        return $proof && strtolower($proof['email']) === strtolower($this->normalizeEmail($email));
    }

    /**
     * The email the current visitor started the verification flow with.
     */
    public function pendingEmail(): ?string
    {
        return session('pending_verify_email');
    }

    /**
     * Seconds remaining before the visitor may request another code. The
     * verification page uses this to run the resend countdown client-side.
     */
    public function cooldownRemaining(?string $email): int
    {
        if (!$email) {
            return 0;
        }

        $payload = Cache::get($this->otpKey($this->normalizeEmail($email)));

        if (!$payload) {
            return 0;
        }

        return $this->remainingSeconds($payload);
    }

    /**
     * Seconds left on a stored code payload before a resend is allowed.
     */
    private function remainingSeconds(array $payload): int
    {
        return max(0, ((int) ($payload['last_sent_at'] ?? 0)) + self::RESEND_SECONDS - now()->getTimestamp());
    }

    private function issueCode(string $email, string $key): bool
    {
        $code = (string) random_int(100000, 999999);

        try {
            Notification::route('mail', $email)->notify(new EmailVerificationNotification($code, $email));
        } catch (\Throwable $e) {
            Log::error('Email verification code delivery failed: ' . $e->getMessage());
            Cache::forget($key);
            return false;
        }

        Cache::put($key, [
            'code_hash'    => hash('sha256', $code),
            'display_code' => $code,
            'attempts'     => 0,
            'last_sent_at' => now()->getTimestamp(),
        ], now()->addMinutes(self::TTL_MINUTES));

        return true;
    }

    private function rateLimit(string $bucket, string $email, ?string $ip): bool
    {
        $key = 'email-verify:' . $bucket . ':' . hash('sha256', $email) . ':' . hash('sha256', $ip ?? '');

        return RateLimiter::attempt($key, self::MAX_SENDS, fn () => true, self::TTL_MINUTES * 60);
    }

    private function resolveEmail(Request $request): ?string
    {
        $email = $request->input('email') ?: session('pending_verify_email');
        if (!$email) {
            return null;
        }

        return $this->normalizeEmail($email);
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private function otpKey(string $email): string
    {
        return 'email_verify:' . $this->normalizeEmail($email);
    }

    private function proofKey(string $token): string
    {
        return 'email_verified_proof:' . $token;
    }
}
