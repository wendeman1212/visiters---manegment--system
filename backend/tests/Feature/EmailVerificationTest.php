<?php

namespace Tests\Feature;

use App\Http\Controllers\EmailVerificationController;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function otpKey(string $email): string
    {
        return 'email_verify:' . strtolower(trim($email));
    }

    private function visitorPayload(string $email = 'visitor@test.com'): array
    {
        return [
            'first_name'            => 'Jane',
            'last_name'             => 'Doe',
            'sex'                   => 'female',
            'age'                   => '28',
            'email'                 => $email,
            'phone'                 => '+251911223344',
            'id_document_no'        => '123456',
            'company_position'      => 'Guest',
            'password'              => 'secret123',
            'password_confirmation' => 'secret123',
        ];
    }

    public function test_registration_is_blocked_without_email_verification(): void
    {
        $response = $this->post('/register', $this->visitorPayload());

        $response->assertRedirect();
        $response->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('visitors', ['email' => 'visitor@test.com']);
    }

    public function test_send_issues_code_and_stores_it_in_cache(): void
    {
        $response = $this->post('/register/verify-email', ['email' => 'Visitor@Test.com']);

        $response->assertRedirect(route('verify-email'));
        $response->assertSessionHas('success');

        $payload = Cache::get($this->otpKey('visitor@test.com'));
        $this->assertNotNull($payload);
        $this->assertSame(0, $payload['attempts']);
        $this->assertSame(6, strlen((string) $payload['display_code']));
        $this->assertSame(hash('sha256', (string) $payload['display_code']), $payload['code_hash']);
    }

    public function test_send_rejects_invalid_email(): void
    {
        $response = $this->post('/register/verify-email', ['email' => 'not-an-email']);

        $response->assertRedirect();
        $response->assertSessionHasErrors('email');
        $this->assertNull(Cache::get($this->otpKey('not-an-email')));
    }

    public function test_verify_rejects_wrong_code(): void
    {
        $this->post('/register/verify-email', ['email' => 'visitor@test.com']);

        $response = $this->post('/register/verify-code', [
            'email' => 'visitor@test.com',
            'code'  => '000000',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('code');
        $this->assertNull(session('email_verified_token'));
    }

    public function test_verify_rejects_expired_code(): void
    {
        Cache::put($this->otpKey('visitor@test.com'), [
            'code_hash'    => hash('sha256', '111111'),
            'display_code' => '111111',
            'attempts'     => 0,
            'last_sent_at' => now()->getTimestamp(),
        ], now()->addSeconds(-1));

        $response = $this->post('/register/verify-code', [
            'email' => 'visitor@test.com',
            'code'  => '111111',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('code');
    }

    public function test_verify_succeeds_and_issues_single_use_proof(): void
    {
        $this->post('/register/verify-email', ['email' => 'visitor@test.com']);
        $code = Cache::get($this->otpKey('visitor@test.com'))['display_code'];

        $response = $this->post('/register/verify-code', [
            'email' => 'visitor@test.com',
            'code'  => $code,
        ]);

        $response->assertRedirect(route('register'));
        $response->assertSessionHas('success');
        $response->assertSessionHas('email_verified_token');

        $token = $response->getSession()->get('email_verified_token');
        $this->assertNotNull(Cache::get('email_verified_proof:' . $token));
        $this->assertNull(Cache::get($this->otpKey('visitor@test.com')));

        // The proof is consumed exactly once and cannot be replayed.
        $controller = app(EmailVerificationController::class);
        $this->assertTrue($controller->consumeProofFor('visitor@test.com'));
        $this->assertFalse($controller->consumeProofFor('visitor@test.com'));
        $this->assertNull(Cache::get('email_verified_proof:' . $token));
    }

    public function test_wrong_code_attempts_are_limited(): void
    {
        $this->post('/register/verify-email', ['email' => 'visitor@test.com']);
        $code = Cache::get($this->otpKey('visitor@test.com'))['display_code'];

        for ($i = 1; $i <= 5; $i++) {
            $this->post('/register/verify-code', [
                'email' => 'visitor@test.com',
                'code'  => '000000',
            ]);
        }

        // The code was invalidated by repeated wrong attempts.
        $this->assertNull(Cache::get($this->otpKey('visitor@test.com')));

        $response = $this->post('/register/verify-code', [
            'email' => 'visitor@test.com',
            'code'  => $code,
        ]);
        $response->assertSessionHasErrors('code');
    }

    public function test_resend_issues_a_fresh_code(): void
    {
        $this->post('/register/verify-email', ['email' => 'visitor@test.com']);
        $first = Cache::get($this->otpKey('visitor@test.com'))['display_code'];

        // Move last_sent_at back so the resend cooldown does not block the test.
        $payload = Cache::get($this->otpKey('visitor@test.com'));
        $payload['last_sent_at'] = now()->getTimestamp() - 120;
        Cache::put($this->otpKey('visitor@test.com'), $payload, now()->addMinutes(15));

        $response = $this->post('/register/resend-code', ['email' => 'visitor@test.com']);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $second = Cache::get($this->otpKey('visitor@test.com'))['display_code'];
        $this->assertNotNull($second);
        $this->assertNotSame($first, $second);

        // The freshly issued code verifies successfully.
        $verify = $this->post('/register/verify-code', [
            'email' => 'visitor@test.com',
            'code'  => $second,
        ]);
        $verify->assertSessionHas('email_verified_token');
    }

    public function test_resend_is_blocked_during_cooldown(): void
    {
        $this->post('/register/verify-email', ['email' => 'visitor@test.com']);

        $response = $this->post('/register/resend-code', ['email' => 'visitor@test.com']);

        $response->assertRedirect();
        $response->assertSessionHasErrors('code');
    }

    public function test_register_page_and_blocked_send_expose_the_cooldown_remaining(): void
    {
        // Nothing has been sent yet, so the register page reports no cooldown.
        $this->get('/register')->assertInertia(fn ($component) => $component
            ->component('Public/Register')
            ->where('cooldown_remaining', 0));

        $this->post('/register/verify-email', ['email' => 'visitor@test.com']);

        // The register page seeds its resend countdown from the server...
        $this->get('/register')->assertInertia(fn ($component) => $component
            ->component('Public/Register')
            ->where('cooldown_remaining', fn ($value) => $value > 0 && $value <= 60));

        // ...and a blocked send reports the seconds that are actually left so
        // the page can run an accurate countdown instead of a dead error.
        $this->post('/register/verify-email', ['email' => 'visitor@test.com'])
            ->assertSessionHasErrors('email')
            ->assertSessionHas('cooldown_remaining', fn ($value) => $value > 0 && $value <= 60);
    }

    public function test_send_rate_limit_blocks_repeated_requests(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/register/verify-email', ['email' => 'visitor@test.com']);
        }

        $response = $this->post('/register/verify-email', ['email' => 'visitor@test.com']);

        $response->assertRedirect();
        $response->assertSessionHasErrors('email');
    }

    public function test_send_delivery_failure_shows_only_delivery_error_and_leaves_no_cooldown(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.timeout' => 2,
        ]);

        try {
            $first = $this->post('/register/verify-email', ['email' => 'visitor@test.com']);
            $first->assertRedirect();
            $first->assertSessionHasErrors('email');
            $this->assertSame(
                'We could not send the verification code right now. Please try again in a few moments.',
                $first->getSession()->get('errors')->first('email')
            );

            // A failed delivery must not leave a usable code behind...
            $this->assertNull(Cache::get($this->otpKey('visitor@test.com')));

            // ...nor a fresh cooldown: retrying immediately attempts delivery again
            // instead of showing the "please wait" cooldown error.
            $second = $this->post('/register/verify-email', ['email' => 'visitor@test.com']);
            $second->assertRedirect();
            $second->assertSessionHasErrors('email');
            $this->assertNotSame(
                'Please wait a moment before requesting another code.',
                $second->getSession()->get('errors')->first('email')
            );
            $this->assertNull(Cache::get($this->otpKey('visitor@test.com')));
        } finally {
            config(['mail.default' => 'array']);
        }
    }

    public function test_send_recovers_once_delivery_succeeds_after_a_failure(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.timeout' => 2,
        ]);

        $failed = $this->post('/register/verify-email', ['email' => 'visitor@test.com']);
        $failed->assertSessionHasErrors('email');
        $this->assertNull(Cache::get($this->otpKey('visitor@test.com')));

        // Restore the working transport; the very next send succeeds and stores a code.
        config(['mail.default' => 'array']);

        $response = $this->post('/register/verify-email', ['email' => 'visitor@test.com']);
        $response->assertRedirect(route('verify-email'));
        $response->assertSessionHas('success');

        $payload = Cache::get($this->otpKey('visitor@test.com'));
        $this->assertNotNull($payload);
        $this->assertSame(6, strlen((string) $payload['display_code']));
    }

    public function test_full_flow_creates_visitor_with_verified_email(): void
    {
        // Simulate the successful verification step exactly as the verify
        // endpoint would leave the state: proof in cache + token in session.
        $token = bin2hex(random_bytes(32));
        Cache::put('email_verified_proof:' . $token, ['email' => 'visitor@test.com'], now()->addMinutes(30));

        $response = $this
            ->withSession(['email_verified_token' => $token])
            ->post('/register', $this->visitorPayload());

        $response->assertRedirect(route('login'));

        $visitor = Visitor::where('email', 'visitor@test.com')->first();
        $this->assertNotNull($visitor);
        $this->assertNotNull($visitor->email_verified_at);

        // Single-use proof was consumed by the successful registration.
        $this->assertNull(Cache::get('email_verified_proof:' . $token));
        $this->assertNull(session('email_verified_token'));
    }

    public function test_complete_flow_send_otp_verify_then_register(): void
    {
        // 1) Send the code (real delivery path — the test mailer accepts it).
        $send = $this->post('/register/verify-email', ['email' => 'visitor@test.com']);
        $send->assertRedirect(route('verify-email'));
        $send->assertSessionHas('success');

        $code = Cache::get($this->otpKey('visitor@test.com'))['display_code'];

        // 2) Verify the emailed code.
        $verified = $this->post('/register/verify-code', [
            'email' => 'visitor@test.com',
            'code'  => $code,
        ]);
        $verified->assertRedirect(route('register'));
        $verified->assertSessionHas('email_verified_token');

        // 3) The register page now reports the email as verified.
        $page = $this->get('/register');
        $page->assertOk();
        $this->assertTrue($page->viewData('page')['props']['email_verified']);

        // 4) Register the account — the backend recognises the verified email.
        $register = $this->post('/register', $this->visitorPayload());
        $register->assertRedirect(route('login'));

        $visitor = Visitor::where('email', 'visitor@test.com')->first();
        $this->assertNotNull($visitor);
        $this->assertNotNull($visitor->email_verified_at);
    }

    public function test_proof_bound_to_another_email_is_rejected(): void
    {
        $token = bin2hex(random_bytes(32));
        Cache::put('email_verified_proof:' . $token, ['email' => 'other@test.com'], now()->addMinutes(30));

        $response = $this
            ->withSession(['email_verified_token' => $token])
            ->post('/register', $this->visitorPayload('visitor@test.com'));

        $response->assertRedirect();
        $response->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('visitors', ['email' => 'visitor@test.com']);
    }

    public function test_verify_email_page_never_exposes_the_code(): void
    {
        $this->post('/register/verify-email', ['email' => 'visitor@test.com']);
        $code = Cache::get($this->otpKey('visitor@test.com'))['display_code'];

        // The verification page must not leak the OTP into the rendered page.
        $page = $this->get('/verify-email');
        $page->assertOk();
        $page->assertDontSee($code);
        $page->assertDontSee('display_code');

        // Neither does the register page.
        $register = $this->get('/register');
        $register->assertOk();
        $register->assertDontSee($code);
        $register->assertDontSee('display_code');

        // And the send/verify endpoints never return it in their bodies.
        $send = $this->post('/register/verify-email', ['email' => 'visitor@test.com']);
        $send->assertDontSee($code);
    }

    public function test_verify_page_redirects_to_register_when_already_verified(): void
    {
        $token = bin2hex(random_bytes(32));
        Cache::put('email_verified_proof:' . $token, ['email' => 'visitor@test.com'], now()->addMinutes(30));

        $response = $this
            ->withSession([
                'email_verified_token' => $token,
                'pending_verify_email' => 'visitor@test.com',
            ])
            ->get('/verify-email');

        $response->assertRedirect(route('register'));
    }

    public function test_verify_email_page_renders_without_any_code_state(): void
    {
        $this->post('/register/verify-email', ['email' => 'visitor@test.com']);

        $page = $this->get('/verify-email');
        $page->assertOk();
        $page->assertSee('visitor@test.com');
        $page->assertDontSee('display_code');
    }
}
