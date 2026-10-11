<?php

namespace App\Http\Controllers\TwoFactor;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\TwoFactor\TwoFactorLogin;
use App\Services\TwoFactor\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Response;

class TwoFactorController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly TwoFactorLogin $login,
    ) {
    }

    // --------------------------------------------------------- login challenge

    public function showChallenge(Request $request): Response|RedirectResponse
    {
        $admin = $this->login->subject($request);
        if (! $admin) {
            return redirect('/login');
        }
        if (! $this->twoFactor->isEnrolled($admin)) {
            return redirect('/two-factor/setup');
        }

        return $this->noStore('two-factor.challenge', [
            'admin' => $admin,
            'rememberDays' => (int) config('two_factor.remember_days', 30),
        ]);
    }

    public function challenge(Request $request): RedirectResponse
    {
        $admin = $this->login->subject($request);
        if (! $admin) {
            return redirect('/login')->withErrors(['email' => 'Sesi verifikasi berakhir. Silakan login ulang.']);
        }

        $request->validate([
            'code' => ['nullable', 'string', 'max:16'],
            'recovery_code' => ['nullable', 'string', 'max:32'],
        ]);

        $result = $this->twoFactor->attempt($admin, $request->input('code'), $request->input('recovery_code'));

        if ($result === 'locked') {
            $this->login->cancel($request);
            if (Auth::guard('web')->check()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return redirect('/login')->withErrors(['email' => 'Verifikasi 2FA dikunci sementara karena kode salah berulang. Coba lagi nanti.']);
        }

        if ($result === 'missing') {
            return back()->withErrors(['code' => 'Masukkan kode 6 digit atau recovery code.']);
        }
        if ($result !== 'ok') {
            return back()->withErrors(['code' => 'Kode tidak valid. Periksa jam di HP Anda atau gunakan recovery code.']);
        }

        $this->login->complete($request, $admin);
        $this->twoFactor->audit($admin, 'two_factor_login', "Admin {$admin->name} ({$admin->email}) login dengan 2FA");

        $response = redirect('/');
        if ($request->boolean('remember_device') && ($cookie = $this->twoFactor->trustCookie($admin))) {
            $response->withCookie($cookie);
        }

        return $response;
    }

    public function cancel(Request $request): RedirectResponse
    {
        $this->login->cancel($request);
        if (Auth::guard('web')->check() && ! $request->session()->get(TwoFactorLogin::PASSED)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect('/login');
    }

    // ------------------------------------------------------------ enrolment

    public function showSetup(Request $request): Response|RedirectResponse
    {
        $admin = $this->setupSubject($request);
        if (! $admin) {
            return redirect('/login');
        }
        if ($this->twoFactor->isEnrolled($admin)) {
            return redirect($this->login->subject($request) ? '/two-factor/challenge' : '/account/security');
        }

        $secret = $request->session()->get(TwoFactorLogin::SETUP_SECRET);
        if (! is_string($secret) || $secret === '') {
            $secret = $this->twoFactor->generateSecret();
            $request->session()->put(TwoFactorLogin::SETUP_SECRET, $secret);
        }

        return $this->noStore('two-factor.setup', [
            'admin' => $admin,
            'qrSvg' => $this->twoFactor->qrCodeSvg($admin, $secret),
            'secret' => trim(chunk_split($secret, 4, ' ')),
            'required' => $this->twoFactor->isRequiredFor($admin),
            'duringLogin' => (bool) $this->login->subject($request),
        ]);
    }

    public function setup(Request $request): RedirectResponse
    {
        $admin = $this->setupSubject($request);
        $secret = $request->session()->get(TwoFactorLogin::SETUP_SECRET);
        if (! $admin || ! is_string($secret) || $secret === '') {
            return redirect('/login')->withErrors(['email' => 'Sesi setup 2FA berakhir. Silakan login ulang.']);
        }
        if ($this->twoFactor->isEnrolled($admin)) {
            return redirect('/two-factor/challenge');
        }

        $request->validate(['code' => ['required', 'string', 'max:16']]);

        if (! $this->twoFactor->verifySetupCode($secret, $request->input('code'))) {
            return back()->withErrors(['code' => 'Kode tidak cocok. Pastikan QR sudah dipindai dan jam di HP sudah benar.']);
        }

        $codes = $this->twoFactor->enable($admin, $secret);
        $this->login->complete($request, $admin);

        return redirect('/two-factor/recovery-codes')->with('two_factor_recovery_codes', $codes);
    }

    public function showRecoveryCodes(Request $request): Response|RedirectResponse
    {
        $codes = $request->session()->get('two_factor_recovery_codes');
        if (! is_array($codes) || $codes === []) {
            return redirect('/account/security');
        }

        return $this->noStore('two-factor.recovery-codes', ['codes' => $codes]);
    }

    // ------------------------------------------------------- account security

    public function account(Request $request): Response
    {
        /** @var Admin $admin */
        $admin = $request->user();
        $record = $this->twoFactor->recordFor($admin);

        return $this->noStore('two-factor.account', [
            'admin' => $admin,
            'enabled' => $this->twoFactor->enabled(),
            'enrolled' => (bool) $record?->confirmed_at,
            'confirmedAt' => $record?->confirmed_at,
            'required' => $this->twoFactor->isRequiredFor($admin),
            'remaining' => $this->twoFactor->remainingRecoveryCodes($admin),
        ]);
    }

    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        /** @var Admin $admin */
        $admin = $request->user();
        $request->validate(['code' => ['required', 'string', 'max:16']]);

        $result = $this->twoFactor->attempt($admin, $request->input('code'), null);
        if ($result !== 'ok') {
            return back()->withErrors(['code' => $result === 'locked' ? 'Verifikasi 2FA dikunci sementara.' : 'Kode 2FA tidak valid.']);
        }

        return redirect('/two-factor/recovery-codes')->with('two_factor_recovery_codes', $this->twoFactor->regenerateRecoveryCodes($admin));
    }

    public function disable(Request $request): RedirectResponse
    {
        /** @var Admin $admin */
        $admin = $request->user();
        if ($this->twoFactor->enabled() && $this->twoFactor->isRequiredFor($admin)) {
            return back()->withErrors(['code' => '2FA wajib untuk peran Anda dan tidak bisa dinonaktifkan.']);
        }

        $request->validate([
            'password' => ['required', 'string'],
            'code' => ['required', 'string', 'max:16'],
        ]);

        if (! Hash::check($request->input('password'), $admin->password)) {
            return back()->withErrors(['password' => 'Password salah.']);
        }
        if ($this->twoFactor->attempt($admin, $request->input('code'), null) !== 'ok') {
            return back()->withErrors(['code' => 'Kode 2FA tidak valid.']);
        }

        $this->twoFactor->disable($admin, 'dinonaktifkan sendiri');

        return redirect('/account/security')->with('status', '2FA dinonaktifkan.');
    }

    // 2FA pages show secrets and recovery codes: never let the browser or a proxy cache them.
    private function noStore(string $view, array $data): Response
    {
        return response()->view($view, $data)->header('Cache-Control', 'no-store, private, max-age=0')->header('Pragma', 'no-cache');
    }

    // Setup is reachable during login (pending) or from the account page (signed in).
    private function setupSubject(Request $request): ?Admin
    {
        $admin = $this->login->subject($request);
        if ($admin) {
            return $admin;
        }
        $user = Auth::guard('web')->user();

        return $user instanceof Admin ? $user : null;
    }
}
