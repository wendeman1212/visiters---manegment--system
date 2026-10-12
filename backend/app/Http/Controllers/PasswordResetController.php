<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Visitor;
use App\Notifications\PasswordResetNotification;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;

class PasswordResetController extends Controller
{
    public function showForgotForm()
    {
        return Inertia::render('Public/ForgotPassword');
    }

    public function sendResetLink(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'contact' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $contact = trim($request->contact);
        session(['password_reset_contact' => $contact]);

        $account = $this->findAccount($contact, $type);

        if (!$account) {
            return redirect()->route('password.verify')
                ->with('success', 'If an account exists, a verification code has been sent.');
        }

        $otp = (string) random_int(100000, 999999);

        Cache::put('password_otp:' . $this->normalizeContact($contact), [
            'otp'   => $otp,
            'type'  => $type,
            'account_id' => $account->getKey(),
            'email' => $account->email,
            'phone' => $account->phone,
        ], now()->addMinutes(15));

        $this->sendOtp($account->email, $account->phone, $otp);

        return redirect()->route('password.verify')
            ->with('success', 'A verification code has been sent to your email and phone.');
    }

    public function showVerifyForm()
    {
        $contact = session('password_reset_contact');

        if (!$contact) {
            return redirect()->route('password.request');
        }

        $otp = null;
        if (!app()->isProduction()) {
            $payload = Cache::get('password_otp:' . $this->normalizeContact($contact));
            $otp = $payload['otp'] ?? null;
        }

        return Inertia::render('Public/VerifyOtp', [
            'contact' => $contact,
            'otp' => $otp,
        ]);
    }

    public function verifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'contact' => 'required|string',
            'otp' => 'required|digits:6',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $contact = $request->contact;
        $key = 'password_otp:' . $this->normalizeContact($contact);
        $payload = Cache::get($key);

        if (!$payload || !hash_equals($payload['otp'], $request->otp)) {
            return back()->withErrors(['otp' => 'Invalid or expired verification code. Please request a new code.'])->withInput();
        }

        $account = $payload['type'] === 'user'
            ? User::find($payload['account_id'])
            : Visitor::find($payload['account_id']);

        if (!$account) {
            return back()->withErrors(['otp' => 'Account not found. Please try the process again.'])->withInput();
        }

        $account->password = $request->password;
        $account->save();

        Cache::forget($key);
        session()->forget('password_reset_contact');

        return redirect()->route('login')
            ->with('success', 'Your password has been reset successfully. Please log in with your new password.');
    }

    public function resendOtp(Request $request)
    {
        $contact = $request->input('contact') ?: session('password_reset_contact');

        if (!$contact) {
            return back()->withErrors(['contact' => 'Please start the password reset process again.']);
        }

        $key = 'password_otp:' . $this->normalizeContact($contact);
        $payload = Cache::get($key);

        if (!$payload) {
            return back()->withErrors(['otp' => 'Your code has expired. Please start the password reset process again.']);
        }

        $otp = (string) random_int(100000, 999999);
        $payload['otp'] = $otp;
        Cache::put($key, $payload, now()->addMinutes(15));

        $this->sendOtp($payload['email'] ?? null, $payload['phone'] ?? null, $otp);

        return back()->with('success', 'A new verification code has been sent.');
    }

    /**
     * Deliver the OTP by email (real SMTP) and SMS (SmsService gateway).
     * Failures are logged instead of crashing the reset flow.
     */
    private function sendOtp(?string $email, ?string $phone, string $otp): void
    {
        if ($email) {
            try {
                Notification::route('mail', $email)->notify(new PasswordResetNotification($otp, $email));
            } catch (\Throwable $e) {
                Log::error('Password reset OTP email failed to ' . $email . ': ' . $e->getMessage());
            }
        }

        if ($phone) {
            SmsService::send($phone, "Your VMS password reset verification code is: {$otp}");
        }
    }

    /**
     * Locate an account (User or Visitor) by email or phone.
     */
    private function findAccount(string $contact, ?string &$type)
    {
        $type = null;
        $contact = trim($contact);

        if (filter_var($contact, FILTER_VALIDATE_EMAIL)) {
            $email = strtolower($contact);

            $user = User::whereRaw('LOWER(TRIM(email)) = ?', [$email])->first();
            if ($user) {
                $type = 'user';
                return $user;
            }

            $visitor = Visitor::whereRaw('LOWER(TRIM(email)) = ?', [$email])->first();
            if ($visitor) {
                $type = 'visitor';
                return $visitor;
            }

            return null;
        }

        $variants = $this->phoneVariants($contact);
        if (empty($variants)) {
            return null;
        }

        $user = User::whereRaw($this->phoneStorageExpression() . ' IN (' . $this->placeholders($variants) . ')', $variants)->first();
        if ($user) {
            $type = 'user';
            return $user;
        }

        $visitor = Visitor::whereRaw($this->phoneStorageExpression() . ' IN (' . $this->placeholders($variants) . ')', $variants)->first();
        if ($visitor) {
            $type = 'visitor';
            return $visitor;
        }

        return null;
    }

    /**
     * All plausible digit forms for the typed phone number so a match is found
     * regardless of the format stored in the database:
     *   - raw digits (handles spaces/dashes/parens/plus in the input)
     *   - Ethiopian E.164 form (e.g. 09 66 12 44 89 -> 251966124489)
     *   - local leading-zero form (e.g. +251 966 12 44 89 -> 0966124489)
     */
    private function phoneVariants(string $contact): array
    {
        $variants = [];

        $raw = preg_replace('/[^0-9]/', '', $contact);
        if ($raw !== '') {
            $variants[] = $raw;
        }

        $e164 = SmsService::normalizeEthiopianPhone($contact);
        if ($e164 !== null) {
            $variants[] = $e164;

            if (strlen($e164) === 12 && str_starts_with($e164, '251')) {
                $variants[] = '0' . substr($e164, 3);
            }
        }

        return array_values(array_unique($variants));
    }

    /**
     * Normalize the stored phone column by stripping separators so every
     * database format (e.g. "09  66 12  44  89", "+93 3456", "0956789867")
     * is comparable against the typed variants.
     */
    private function phoneStorageExpression(): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', ''), '.', ''), '+', ''), '/', '')";
    }

    private function placeholders(array $values): string
    {
        return rtrim(str_repeat('?,', count($values)), ',');
    }

    private function normalizeContact(string $contact): string
    {
        $contact = strtolower(trim($contact));
        return preg_replace('/[^a-z0-9@.]/', '', $contact);
    }

    public function showResetForm($token)
    {
        return Inertia::render('Public/ResetPassword', [
            'token' => $token,
            'email' => request('email'),
        ]);
    }

    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
            'token' => 'required',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $credentials = $request->only('email', 'password', 'password_confirmation', 'token');

        if (User::where('email', $request->email)->exists()) {
            $status = Password::broker('users')->reset($credentials, function ($user, $password) {
                $user->password = $password;
                $user->save();
            });
        } elseif (Visitor::where('email', $request->email)->exists()) {
            $status = Password::broker('visitors')->reset($credentials, function ($visitor, $password) {
                $visitor->password = $password;
                $visitor->save();
            });
        } else {
            $status = Password::INVALID_USER;
        }

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('success', 'Your password has been reset successfully. Please log in with your new password.');
        }

        $errorMessage = $status === Password::INVALID_USER
            ? 'We could not find an account with that email address.'
            : 'Invalid or expired reset token. Please try the password reset process again.';

        return back()->withErrors(['email' => $errorMessage])->withInput();
    }
}
