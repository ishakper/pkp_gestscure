<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Services\TwoFactor\TwoFactorLogin;
use App\Services\TwoFactor\TwoFactorService;
use Closure;
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
        if (! $admin || $this->isThrottled($request)) {
            return $next($request);
        }

        if (! $this->twoFactor->appliesTo($admin)) {
            return $this->passThroughAndMark($request, $next);
        }

        $this->hitThrottle($request);

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
        if (! $admin || $this->isThrottled($request) || ! $this->twoFactor->appliesTo($admin)) {
            return $next($request);
        }

        $this->hitThrottle($request);

        if (! $this->twoFactor->isEnrolled($admin)) {
            return response()->json([
                'status' => 'error',
                'code' => 403,
                'two_factor_setup_required' => true,
                'message' => 'Akun ini wajib memakai 2FA. Aktifkan dulu melalui login di halaman web.',
            ], 403);
        }

        $result = $this->twoFactor->attempt($admin, $request->input('two_factor_code'), $request->input('recovery_code'));

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

        $missing = blank($request->input('two_factor_code')) && blank($request->input('recovery_code'));

        return response()->json([
            'status' => 'error',
            'code' => 401,
            'two_factor_required' => true,
            'message' => $missing ? 'Masukkan kode 2FA (two_factor_code) atau recovery_code.' : 'Kode 2FA tidak valid.',
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

    // The original routes are throttled by the named `login` limiter, which runs
    // after this middleware. Requests stopped here are counted against the same
    // limiter so the password-check path cannot be used to bypass it.
    private function isThrottled(Request $request): bool
    {
        foreach ($this->loginLimits($request) as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return true;
            }
        }

        return false;
    }

    private function hitThrottle(Request $request): void
    {
        foreach ($this->loginLimits($request) as [$key, $max, $decay]) {
            RateLimiter::hit($key, $decay);
        }
    }

    /** @return array<int, array{0:string,1:int,2:int}> */
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
            $limits[] = [$key, $limit->maxAttempts, $limit->decayMinutes * 60];
        }

        return $limits;
    }

    private function lockedMessage(?\DateTimeInterface $until): string
    {
        $minutes = $until ? max(1, (int) ceil(($until->getTimestamp() - time()) / 60)) : (int) config('two_factor.lockout_minutes', 15);

        return "Verifikasi 2FA dikunci sementara karena kode salah berulang. Coba lagi dalam {$minutes} menit.";
    }
}
