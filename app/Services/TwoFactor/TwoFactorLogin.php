<?php

namespace App\Services\TwoFactor;

use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Session state for a login that passed the password check and is waiting for
 * its 2FA step, plus the final sign-in once the step succeeds.
 */
class TwoFactorLogin
{
    public const PENDING = 'two_factor.pending';
    public const PASSED = 'two_factor.passed';
    public const SETUP_SECRET = 'two_factor.setup_secret';

    public function begin(Request $request, Admin $admin): void
    {
        $request->session()->forget(self::SETUP_SECRET);
        $request->session()->put(self::PENDING, [
            'admin_id' => $admin->getKey(),
            'expires_at' => now()->addMinutes((int) config('two_factor.pending_minutes', 5))->getTimestamp(),
        ]);
    }

    /**
     * The admin this 2FA step is for: a pending password-verified login, or an
     * already signed-in admin whose session has not passed 2FA yet (e.g. a
     * session that started before 2FA was switched on).
     */
    public function subject(Request $request): ?Admin
    {
        $pending = $request->session()->get(self::PENDING);
        if (is_array($pending) && ($pending['expires_at'] ?? 0) >= now()->getTimestamp()) {
            return Admin::find($pending['admin_id'] ?? null);
        }
        if ($pending) {
            $this->cancel($request);
        }

        $user = Auth::guard('web')->user();

        return $user instanceof Admin && ! $request->session()->get(self::PASSED) ? $user : null;
    }

    public function isPending(Request $request): bool
    {
        return is_array($request->session()->get(self::PENDING));
    }

    public function cancel(Request $request): void
    {
        $request->session()->forget([self::PENDING, self::SETUP_SECRET]);
    }

    public function markPassed(Request $request): void
    {
        $request->session()->put(self::PASSED, true);
    }

    /**
     * Signs the admin in. Mirrors the session setup done by the POST /login
     * route in routes/web.php (session regeneration + web-session-token), which
     * is left unchanged. Keep both in sync if that route ever changes.
     */
    public function complete(Request $request, Admin $admin): void
    {
        $this->cancel($request);

        if (Auth::guard('web')->id() !== $admin->getKey()) {
            Auth::login($admin);
            $request->session()->regenerate();
            $token = $admin->createToken('web-session-token');
            $request->session()->put([
                'api_token' => $token->plainTextToken,
                'api_token_id' => $token->accessToken->getKey(),
            ]);
        } else {
            $request->session()->regenerate();
        }

        $this->markPassed($request);
    }
}
