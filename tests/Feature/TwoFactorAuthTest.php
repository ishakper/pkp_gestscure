<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminTwoFactor;
use App\Services\TwoFactor\TwoFactorLogin;
use App\Services\TwoFactor\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorAuthTest extends TestCase
{
    use RefreshDatabase;

    private Google2FA $google2fa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->google2fa = new Google2FA();
        config(['two_factor.enabled' => true, 'two_factor.required_roles' => ['super_admin']]);
    }

    // ------------------------------------------------------------ switch off

    public function test_login_is_unchanged_when_two_factor_is_disabled(): void
    {
        config(['two_factor.enabled' => false]);
        $admin = $this->admin('super_admin');

        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($admin);
        $this->assertNotNull(session('api_token'));

        $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertOk()->assertJsonStructure(['data' => ['token']]);
    }

    // ---------------------------------------------------- first-time enrolment

    public function test_required_admin_must_enrol_before_being_signed_in(): void
    {
        $admin = $this->admin('super_admin');

        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect('/two-factor/setup');
        $this->assertGuest();
        $this->get('/')->assertRedirect('/login');

        $this->get('/two-factor/setup')->assertOk()->assertSee('<svg', false)->assertSee('Aktifkan 2FA');
        $secret = session(TwoFactorLogin::SETUP_SECRET);
        $this->assertIsString($secret);

        $this->post('/two-factor/setup', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post('/two-factor/setup', ['code' => $this->google2fa->getCurrentOtp($secret)])
            ->assertRedirect('/two-factor/recovery-codes');
        $this->assertAuthenticatedAs($admin);
        $this->assertNotNull(session('api_token'), 'dashboard needs the same web-session-token as the original login');

        $codes = session('two_factor_recovery_codes');
        $this->assertCount(8, $codes);
        $this->get('/two-factor/recovery-codes')->assertOk()->assertSee($codes[0]);
        $this->get('/')->assertOk();

        $record = AdminTwoFactor::where('admin_id', $admin->id)->first();
        $this->assertNotNull($record->confirmed_at);
        $this->assertNotSame($secret, $record->getRawOriginal('secret'), 'secret is encrypted at rest');
        $this->assertNotContains($codes[0], $record->recovery_codes, 'recovery codes are stored hashed');
    }

    // ------------------------------------------------------------- challenge

    public function test_enrolled_admin_is_challenged_and_signed_in_with_a_valid_code(): void
    {
        $admin = $this->admin('super_admin');
        $secret = $this->enrol($admin);

        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect('/two-factor/challenge');
        $this->assertGuest();
        $this->get('/two-factor/challenge')->assertOk()->assertSee($admin->email);

        $this->post('/two-factor/challenge', ['code' => '123456'])->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post('/two-factor/challenge', ['code' => $this->google2fa->getCurrentOtp($secret)])->assertRedirect('/');
        $this->assertAuthenticatedAs($admin);
        $this->get('/')->assertOk();
        $this->assertDatabaseHas('activity_logs', ['admin_id' => $admin->id, 'action' => 'two_factor_login']);
    }

    public function test_a_code_cannot_be_replayed(): void
    {
        $admin = $this->admin('super_admin');
        $secret = $this->enrol($admin);
        $code = $this->google2fa->getCurrentOtp($secret);

        $this->post('/login', ['email' => $admin->email, 'password' => 'password']);
        $this->post('/two-factor/challenge', ['code' => $code])->assertRedirect('/');
        $this->post('/logout');

        $this->post('/login', ['email' => $admin->email, 'password' => 'password']);
        $this->post('/two-factor/challenge', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_recovery_code_works_once(): void
    {
        $admin = $this->admin('super_admin');
        $this->enrol($admin);
        $codes = app(TwoFactorService::class)->regenerateRecoveryCodes($admin);

        $this->post('/login', ['email' => $admin->email, 'password' => 'password']);
        $this->post('/two-factor/challenge', ['recovery_code' => strtolower($codes[0])])->assertRedirect('/');
        $this->assertAuthenticatedAs($admin);
        $this->post('/logout');

        $this->post('/login', ['email' => $admin->email, 'password' => 'password']);
        $this->post('/two-factor/challenge', ['recovery_code' => $codes[0]])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->assertSame(7, app(TwoFactorService::class)->remainingRecoveryCodes($admin));
    }

    public function test_repeated_wrong_codes_lock_the_account(): void
    {
        config(['two_factor.max_attempts' => 3]);
        $admin = $this->admin('super_admin');
        $secret = $this->enrol($admin);

        $this->post('/login', ['email' => $admin->email, 'password' => 'password']);
        $this->post('/two-factor/challenge', ['code' => '111111'])->assertSessionHasErrors('code');
        $this->post('/two-factor/challenge', ['code' => '222222'])->assertSessionHasErrors('code');
        $this->post('/two-factor/challenge', ['code' => '333333'])->assertRedirect('/login');

        // Locked: even the correct code is refused, and a new password login is stopped too.
        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'password', 'two_factor_code' => $this->google2fa->getCurrentOtp($secret)])
            ->assertStatus(423);
        $this->assertDatabaseHas('activity_logs', ['admin_id' => $admin->id, 'action' => 'two_factor_locked']);
    }

    public function test_pending_login_expires(): void
    {
        $admin = $this->admin('super_admin');
        $secret = $this->enrol($admin);

        $this->post('/login', ['email' => $admin->email, 'password' => 'password']);
        $this->travel(6)->minutes();

        $this->post('/two-factor/challenge', ['code' => $this->google2fa->getCurrentOtp($secret)])->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_wrong_password_keeps_the_original_behaviour(): void
    {
        $admin = $this->admin('super_admin');
        $this->enrol($admin);

        $this->post('/login', ['email' => $admin->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertFalse(session()->has(TwoFactorLogin::PENDING), 'a wrong password never reaches the 2FA step');

        $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'wrong'])
            ->assertStatus(401)->assertJsonMissing(['two_factor_required' => true]);
    }

    // -------------------------------------------------------- trusted device

    public function test_remembered_device_skips_the_challenge(): void
    {
        $admin = $this->admin('super_admin');
        $secret = $this->enrol($admin);

        $this->post('/login', ['email' => $admin->email, 'password' => 'password']);
        $response = $this->post('/two-factor/challenge', ['code' => $this->google2fa->getCurrentOtp($secret), 'remember_device' => '1']);
        $cookieName = TwoFactorService::TRUST_COOKIE_PREFIX.$admin->id;
        $response->assertCookie($cookieName);
        $cookie = $response->getCookie($cookieName, true);
        $this->post('/logout');

        $this->withCookie($cookieName, $cookie->getValue())
            ->post('/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($admin);
        $this->get('/')->assertOk();
    }

    // ---------------------------------------------------------------- API

    public function test_api_login_requires_the_code_for_enrolled_admins(): void
    {
        $admin = $this->admin('super_admin');
        $secret = $this->enrol($admin);

        $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertStatus(401)->assertJson(['two_factor_required' => true]);

        $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'password', 'two_factor_code' => '000000'])
            ->assertStatus(401)->assertJson(['two_factor_required' => true]);

        $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'password', 'two_factor_code' => $this->google2fa->getCurrentOtp($secret)])
            ->assertOk()->assertJsonStructure(['data' => ['token']]);
    }

    public function test_api_login_is_refused_until_a_required_admin_has_enrolled(): void
    {
        $admin = $this->admin('super_admin');

        $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertStatus(403)->assertJson(['two_factor_setup_required' => true]);
    }

    // ------------------------------------------------------------- scope

    public function test_roles_that_are_not_required_log_in_normally(): void
    {
        $admin = $this->admin('building_admin');

        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($admin);
        $this->get('/')->assertOk();

        $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'password'])->assertOk();
    }

    public function test_session_opened_before_two_factor_was_enabled_is_challenged(): void
    {
        $admin = $this->admin('super_admin');
        $secret = $this->enrol($admin);

        $this->actingAs($admin)->get('/')->assertRedirect('/two-factor/challenge');
        $this->post('/two-factor/challenge', ['code' => $this->google2fa->getCurrentOtp($secret)])->assertRedirect('/');
        $this->get('/')->assertOk();
    }

    // ------------------------------------------------------- account page

    public function test_required_role_cannot_disable_two_factor(): void
    {
        $admin = $this->admin('super_admin');
        $secret = $this->enrol($admin);
        $this->signInWithTwoFactor($admin, $secret);

        $this->get('/account/security')->assertOk()->assertSee('AKTIF')->assertDontSee('Nonaktifkan 2FA</button>', false);
        $this->post('/account/security/disable', ['password' => 'password', 'code' => '000000'])->assertSessionHasErrors('code');
        $this->assertDatabaseHas('admin_two_factor', ['admin_id' => $admin->id]);
    }

    public function test_optional_role_can_enrol_and_disable_from_the_account_page(): void
    {
        $admin = $this->admin('building_admin');
        $this->post('/login', ['email' => $admin->email, 'password' => 'password']);

        $this->get('/two-factor/setup')->assertOk();
        $secret = session(TwoFactorLogin::SETUP_SECRET);
        $this->post('/two-factor/setup', ['code' => $this->google2fa->getCurrentOtp($secret)])->assertRedirect('/two-factor/recovery-codes');

        AdminTwoFactor::where('admin_id', $admin->id)->update(['last_used_step' => null]);
        $this->post('/account/security/disable', ['password' => 'password', 'code' => $this->google2fa->getCurrentOtp($secret)])
            ->assertRedirect('/account/security');
        $this->assertDatabaseMissing('admin_two_factor', ['admin_id' => $admin->id]);
    }

    // ------------------------------------------------- rate limit / hardening

    /**
     * Regression: with throttling active, a correct password must never reach the
     * original login route once the limit is hit (it signed the admin in without 2FA).
     */
    public function test_rate_limited_web_login_never_signs_in_without_two_factor(): void
    {
        $this->enableRealThrottle(3);
        $admin = $this->admin('super_admin');
        $this->enrol($admin);

        $statuses = [];
        for ($i = 0; $i < 6; $i++) {
            $statuses[] = $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->status();
            $this->assertGuest();
        }
        $this->assertContains(429, $statuses);
        $this->assertNotContains(200, $statuses);
    }

    public function test_rate_limited_api_login_never_issues_a_token_without_two_factor(): void
    {
        $this->enableRealThrottle(3);
        $admin = $this->admin('super_admin');
        $this->enrol($admin);

        for ($i = 0; $i < 6; $i++) {
            $response = $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'password']);
            $this->assertContains($response->status(), [401, 429]);
            $this->assertNull($response->json('data.token'));
        }
        $this->assertSame(0, $admin->tokens()->count());
    }

    public function test_wrong_passwords_then_correct_password_is_still_throttled(): void
    {
        $this->enableRealThrottle(3);
        $admin = $this->admin('super_admin');
        $this->enrol($admin);

        for ($i = 0; $i < 3; $i++) {
            $this->post('/login', ['email' => $admin->email, 'password' => 'wrong']);
        }
        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_api_ignores_non_string_codes_and_code_less_probes_do_not_lock(): void
    {
        $admin = $this->admin('super_admin');
        $secret = $this->enrol($admin);

        $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'password', 'two_factor_code' => ['123456']])
            ->assertStatus(401)->assertJson(['two_factor_required' => true]);

        config(['two_factor.login_attempts_per_minute' => 50]);
        for ($i = 0; $i < 8; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'password'])->assertStatus(401);
        }
        $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'password', 'two_factor_code' => $this->google2fa->getCurrentOtp($secret)])
            ->assertOk();
    }

    public function test_empty_web_submissions_do_not_count_towards_lockout(): void
    {
        config(['two_factor.max_attempts' => 2]);
        $admin = $this->admin('super_admin');
        $secret = $this->enrol($admin);

        $this->post('/login', ['email' => $admin->email, 'password' => 'password']);
        $this->post('/two-factor/challenge', ['code' => ''])->assertSessionHasErrors('code');
        $this->post('/two-factor/challenge', ['code' => ''])->assertSessionHasErrors('code');
        $this->post('/two-factor/challenge', ['code' => $this->google2fa->getCurrentOtp($secret)])->assertRedirect('/');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_two_factor_pages_are_not_cacheable(): void
    {
        $admin = $this->admin('super_admin');
        $this->post('/login', ['email' => $admin->email, 'password' => 'password']);

        $cacheControl = (string) $this->get('/two-factor/setup')->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
    }

    // ------------------------------------------------------ emergency reset

    public function test_reset_command_removes_two_factor(): void
    {
        $admin = $this->admin('super_admin');
        $this->enrol($admin);

        $this->artisan('securegate:two-factor:reset', ['email' => $admin->email, '--force' => true])->assertSuccessful();

        $this->assertDatabaseMissing('admin_two_factor', ['admin_id' => $admin->id]);
        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect('/two-factor/setup');
    }

    // ------------------------------------------------------------- helpers

    // tests/TestCase.php disables ThrottleRequests for every test; re-enable it here.
    private function enableRealThrottle(int $perMinute): void
    {
        $this->app->forgetInstance(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        \Illuminate\Support\Facades\RateLimiter::for('login', fn (\Illuminate\Http\Request $request) => \Illuminate\Cache\RateLimiting\Limit::perMinute($perMinute)
            ->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
        config(['two_factor.login_attempts_per_minute' => $perMinute]);
    }

    private function admin(string $role): Admin
    {
        return Admin::create([
            'name' => ucfirst($role),
            'email' => $role.'-2fa@example.test',
            'password' => Hash::make('password'),
            'role' => $role,
        ]);
    }

    private function enrol(Admin $admin): string
    {
        $service = app(TwoFactorService::class);
        $secret = $service->generateSecret();
        $service->enable($admin, $secret);
        // enable() blocks the code used during setup; tests log in within the same 30-second step.
        AdminTwoFactor::where('admin_id', $admin->id)->update(['last_used_step' => null]);

        return $secret;
    }

    private function signInWithTwoFactor(Admin $admin, string $secret): void
    {
        $this->post('/login', ['email' => $admin->email, 'password' => 'password']);
        $this->post('/two-factor/challenge', ['code' => $this->google2fa->getCurrentOtp($secret)])->assertRedirect('/');
        AdminTwoFactor::where('admin_id', $admin->id)->update(['last_used_step' => null]);
    }
}
