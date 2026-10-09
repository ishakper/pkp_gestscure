<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Services\TwoFactor\TwoFactorLogin;
use App\Services\TwoFactor\TwoFactorService;
use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds a TOTP step to the existing logins without modifying them. Pushed onto
 * the `web` and `api` middleware groups by TwoFactorServiceProvider.
 *
 *  - POST /login: once the password is known to be correct, the request is
 *    stopped before the original route signs the admin in, and the browser is
 *    sent to the 2FA challenge (or to first-time setup).
 *  - POST /api/v1/auth/login: requires `two_factor_code` or `recovery_code` in
 *    the same request before the original controller issues a token.
 *  - Any other web request from a signed-in admin whose session has not passed
 *    2FA (e.g. a session opened before 2FA was switched on) is sent to the
 *    challenge.
 *
 * Wrong passwords always fall through to the original routes, so their error
 * messages and `throttle:login` behaviour are unchanged.
 *
 * Once the password is known to be correct and 2FA applies, the request must
 * NEVER reach the original login route or controller: they would sign the admin
 * in (or issue a token) without the second factor. Rate limiting on that path is
 * therefore enforced here, with a 429, instead of being left to `throttle:login`
 * (which Laravel's middleware priority may run before or after this class).
 */
class EnforceTwoFactor
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly TwoFactorLogin $login,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->twoFactor->enabled()) {
            return $next($request);
        }

        if ($request->isMethod('POST') && $request->is('login')) {
            return $this->webLogin($request, $next);
        }

        if ($request->isMethod('POST') && $request->is('api/v1/auth/login')) {
            return $this->apiLogin($request, $next);
        }

        if ($request->hasSession() && Auth::guard('web')->check()) {
            return $this->guardSession($request, $next);
        }

        return $next($request);
    }

    private function webLogin(Request $request, Closure $next): Response
    {
        $admin = $this->passwordVerifiedAdmin($request);
        if (! $admin) {
            return $next($request);
        }

        if (! $this->twoFactor->appliesTo($admin)) {
            return $this->passThroughAndMark($request, $next);
        }

        $this->enforceRateLimit($request);

        // Only a device that already passed 2FA may continue to the original route.
        if ($this->twoFactor->isEnrolled($admin) && $this->twoFactor->hasTrustedDevice($request, $admin)) {
            return $this->passThroughAndMark($request, $next);
        }

        if ($until = $this->twoFactor->lockedUntil($admin)) {
            return redirect('/login')->withErrors(['email' => $this->lockedMessage($until)]);
        }

        $this->login->begin($request, $admin);

        return redirect($this->twoFactor->isEnrolled($admin) ? '/two-factor/challenge' : '/two-factor/setup');
    }

    private function apiLogin(Request $request, Closure $next): Response
    {
        $admin = $this->passwordVerifiedAdmin($request);
        if (! $admin || ! $this->twoFactor->appliesTo($admin)) {
            return $next($request);
        }

        $this->enforceRateLimit($request);

        if (! $this->twoFactor->isEnrolled($admin)) {
            return response()->json([
                'status' => 'error',
                'code' => 403,
                'two_factor_setup_required' => true,
                'message' => 'Akun ini wajib memakai 2FA. Aktifkan dulu melalui login di halaman web.',
            ], 403);
        }

        $code = $this->stringInput($request, 'two_factor_code');
        $recovery = $this->stringInput($request, 'recovery_code');
        if ($code === null && $recovery === null) {
            // Not a failed attempt: clients often call once without a code to learn that 2FA is needed.
            return response()->json([
                'status' => 'error',
                'code' => 401,
                'two_factor_required' => true,
                'message' => 'Masukkan kode 2FA (two_factor_code) atau recovery_code.',
            ], 401);
        }

        $result = $this->twoFactor->attempt($admin, $code, $recovery);

        if ($result === 'ok') {
            return $next($request);
        }

        if ($result === 'locked') {
            return response()->json([
                'status' => 'error',
                'code' => 423,
                'message' => $this->lockedMessage($this->twoFactor->lockedUntil($admin)),
            ], 423);
        }

        return response()->json([
            'status' => 'error',
            'code' => 401,
            'two_factor_required' => true,
            'message' => 'Kode 2FA tidak valid.',
        ], 401);
    }

    private function guardSession(Request $request, Closure $next): Response
    {
        if ($request->session()->get(TwoFactorLogin::PASSED)) {
            return $next($request);
        }

        $admin = Auth::guard('web')->user();
        if (! $admin instanceof Admin || ! $this->twoFactor->appliesTo($admin)) {
            $this->login->markPassed($request);

            return $next($request);
        }

        if ($request->is('two-factor/*') || $request->is('logout')) {
            return $next($request);
        }

        if ($this->twoFactor->isEnrolled($admin) && $this->twoFactor->hasTrustedDevice($request, $admin)) {
            $this->login->markPassed($request);

            return $next($request);
        }

        if ($request->expectsJson() || ! $request->isMethod('GET')) {
            return response()->json(['status' => 'error', 'code' => 403, 'two_factor_required' => true, 'message' => 'Verifikasi 2FA diperlukan.'], 403);
        }

        return redirect($this->twoFactor->isEnrolled($admin) ? '/two-factor/challenge' : '/two-factor/setup');
    }

    private function passThroughAndMark(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (Auth::guard('web')->check() && $request->hasSession()) {
            $this->login->markPassed($request);
        }

        return $response;
    }

    private function passwordVerifiedAdmin(Request $request): ?Admin
    {
        $email = $request->input('email');
        $password = $request->input('password');
        if (! is_string($email) || ! is_string($password) || $email === '' || $password === '') {
            return null;
        }

        $admin = Admin::where('email', $email)->first();

        return $admin && Hash::check($password, $admin->password) ? $admin : null;
    }

    /**
     * Rate limit for password-verified logins that the 2FA layer handles itself.
     * Counts against a dedicated key (so it works whatever the middleware order)
     * and also honours the existing `login` limiter. Throws 429 when exceeded.
     */
    private function enforceRateLimit(Request $request): void
    {
        $email = strtolower(trim((string) $request->input('email')));
        $own = 'two-factor-login:'.sha1($email.'|'.$request->ip());
        $max = (int) config('two_factor.login_attempts_per_minute', 5);

        $limited = RateLimiter::tooManyAttempts($own, $max);
        foreach ($this->loginLimits($request) as [$key, $limit]) {
            $limited = $limited || RateLimiter::tooManyAttempts($key, $limit);
        }

        if ($limited) {
            $retry = max(1, RateLimiter::availableIn($own));
            throw new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => $retry]);
        }

        RateLimiter::hit($own, 60);
    }

    private function stringInput(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** @return array<int, array{0:string,1:int}> */
    private function loginLimits(Request $request): array
    {
        $limiter = RateLimiter::limiter('login');
        if (! $limiter) {
            return [];
        }

        $limits = [];
        foreach (Arr::wrap($limiter($request)) as $limit) {
            if ($limit->maxAttempts >= PHP_INT_MAX) {
                continue;
            }
            $key = (new \ReflectionProperty(ThrottleRequests::class, 'shouldHashKeys'))->getValue()
                ? md5('login'.$limit->key)
                : 'login:'.$limit->key;
            $limits[] = [$key, $limit->maxAttempts];
        }

        return $limits;
    }

    private function lockedMessage(?\DateTimeInterface $until): string
    {
        $minutes = $until ? max(1, (int) ceil(($until->getTimestamp() - time()) / 60)) : (int) config('two_factor.lockout_minutes', 15);

        return "Verifikasi 2FA dikunci sementara karena kode salah berulang. Coba lagi dalam {$minutes} menit.";
    }
}
