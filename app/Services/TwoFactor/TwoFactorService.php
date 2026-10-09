<?php

namespace App\Services\TwoFactor;

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\AdminTwoFactor;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * TOTP two-factor authentication for admins. Holds all 2FA rules so the
 * middleware and controllers stay thin. Secrets and recovery codes live in the
 * separate `admin_two_factor` table; the core `admins` table is never written.
 */
class TwoFactorService
{
    public const TRUST_COOKIE_PREFIX = 'sg_2fa_trust_';

    public function __construct(private readonly Google2FA $google2fa)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('two_factor.enabled');
    }

    public function isRequiredFor(Admin $admin): bool
    {
        return in_array($admin->role, (array) config('two_factor.required_roles', []), true);
    }

    public function recordFor(Admin $admin): ?AdminTwoFactor
    {
        return AdminTwoFactor::where('admin_id', $admin->getKey())->first();
    }

    public function isEnrolled(Admin $admin): bool
    {
        return (bool) $this->recordFor($admin)?->confirmed_at;
    }

    /** Whether this admin must pass a 2FA step to log in. */
    public function appliesTo(Admin $admin): bool
    {
        return $this->enabled() && ($this->isRequiredFor($admin) || $this->isEnrolled($admin));
    }

    // ---------------------------------------------------------------- enrolment

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function otpauthUrl(Admin $admin, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl((string) config('two_factor.issuer'), $admin->email, $secret);
    }

    public function qrCodeSvg(Admin $admin, string $secret): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd()));

        return $writer->writeString($this->otpauthUrl($admin, $secret));
    }

    /** Checks a code against a secret that is not saved yet (enrolment). */
    public function verifySetupCode(string $secret, string $code): bool
    {
        $code = $this->normalizeCode($code);

        return strlen($code) === 6 && $this->google2fa->verifyKey($secret, $code, 1) !== false;
    }

    /**
     * Saves a confirmed secret and returns fresh plain-text recovery codes
     * (shown once, stored only as hashes).
     *
     * @return array<int, string>
     */
    public function enable(Admin $admin, string $secret): array
    {
        $codes = $this->makeRecoveryCodes();

        AdminTwoFactor::updateOrCreate(
            ['admin_id' => $admin->getKey()],
            [
                'secret' => $secret,
                'recovery_codes' => array_map([$this, 'hashRecoveryCode'], $codes),
                'confirmed_at' => now(),
                'last_used_step' => $this->google2fa->getTimestamp(),
                'failed_attempts' => 0,
                'locked_until' => null,
            ]
        );

        $this->audit($admin, 'two_factor_enabled', "2FA diaktifkan untuk {$admin->email}");

        return $codes;
    }

    public function disable(Admin $admin, string $reason): void
    {
        AdminTwoFactor::where('admin_id', $admin->getKey())->delete();
        $this->audit($admin, 'two_factor_disabled', "2FA dinonaktifkan untuk {$admin->email} ({$reason})");
    }

    /** @return array<int, string> */
    public function regenerateRecoveryCodes(Admin $admin): array
    {
        $record = $this->recordFor($admin);
        $codes = $this->makeRecoveryCodes();
        $record->recovery_codes = array_map([$this, 'hashRecoveryCode'], $codes);
        $record->save();
        $this->audit($admin, 'two_factor_recovery_regenerated', "Recovery code 2FA dibuat ulang untuk {$admin->email}");

        return $codes;
    }

    public function remainingRecoveryCodes(Admin $admin): int
    {
        return count((array) $this->recordFor($admin)?->recovery_codes);
    }

    // ------------------------------------------------------------- verification

    /**
     * Verifies a TOTP code or a recovery code and applies lockout rules.
     * Returns one of: 'ok', 'invalid', 'locked', 'missing'. An empty submission
     * is 'missing' and does not count towards the lockout.
     */
    public function attempt(Admin $admin, ?string $code, ?string $recoveryCode): string
    {
        $code = $code !== null && trim($code) !== '' ? $code : null;
        $recoveryCode = $recoveryCode !== null && trim($recoveryCode) !== '' ? $recoveryCode : null;
        if ($code === null && $recoveryCode === null) return 'missing';

        $connection = (new AdminTwoFactor())->getConnection();

        return $connection->transaction(function () use ($admin, $code, $recoveryCode): string {
            $record = AdminTwoFactor::query()->where('admin_id', $admin->getKey())->lockForUpdate()->first();
            if (! $record || ! $record->confirmed_at) return 'invalid';
            if ($record->isLocked()) return 'locked';

            $valid = $code !== null ? $this->consumeTotp($record, $code) : $this->consumeRecoveryCode($admin, $record, $recoveryCode);
            if ($valid) {
                $record->forceFill(['failed_attempts' => 0, 'locked_until' => null])->save();
                return 'ok';
            }

            $failed = (int) $record->failed_attempts + 1;
            $maxAttempts = (int) config('two_factor.max_attempts', 5);
            if ($failed >= $maxAttempts) {
                $minutes = (int) config('two_factor.lockout_minutes', 15);
                $record->forceFill(['failed_attempts' => 0, 'locked_until' => now()->addMinutes($minutes)])->save();
                $this->audit($admin, 'two_factor_locked', "2FA {$admin->email} dikunci {$minutes} menit setelah kode salah berulang");
                return 'locked';
            }

            $record->forceFill(['failed_attempts' => $failed])->save();
            $this->audit($admin, 'two_factor_failed', "Kode 2FA salah untuk {$admin->email} (percobaan {$failed})");
            return 'invalid';
        }, 3);
    }
    public function lockedUntil(Admin $admin): ?\Illuminate\Support\Carbon
    {
        $record = $this->recordFor($admin);

        return $record && $record->isLocked() ? $record->locked_until : null;
    }

    private function consumeTotp(AdminTwoFactor $record, string $code): bool
    {
        $code = $this->normalizeCode($code);
        if (strlen($code) !== 6) {
            return false;
        }

        // Only codes newer than the last accepted one are valid, so a code cannot be replayed.
        $step = $this->google2fa->verifyKeyNewer($record->secret, $code, (int) ($record->last_used_step ?? 0), 1);
        if ($step === false) {
            return false;
        }

        $record->forceFill(['last_used_step' => $step])->save();

        return true;
    }

    private function consumeRecoveryCode(Admin $admin, AdminTwoFactor $record, string $code): bool
    {
        $hash = $this->hashRecoveryCode($code);
        $codes = (array) $record->recovery_codes;
        foreach ($codes as $i => $stored) {
            if (hash_equals((string) $stored, $hash)) {
                unset($codes[$i]);
                $record->recovery_codes = array_values($codes);
                $record->save();
                $this->audit($admin, 'two_factor_recovery_used', "Recovery code 2FA dipakai oleh {$admin->email} (sisa ".count($codes).')');

                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------- trusted devices

    public function trustCookie(Admin $admin): ?Cookie
    {
        $days = (int) config('two_factor.remember_days', 30);
        $record = $this->recordFor($admin);
        if ($days <= 0 || ! $record) {
            return null;
        }

        $expires = now()->addDays($days)->getTimestamp();
        $value = $expires.'|'.$this->trustSignature($admin, $record, $expires);

        // Encrypted by the web group's EncryptCookies middleware.
        return cookie(self::TRUST_COOKIE_PREFIX.$admin->getKey(), $value, $days * 24 * 60, null, null, null, true, false, 'lax');
    }

    public function hasTrustedDevice(Request $request, Admin $admin): bool
    {
        $record = $this->recordFor($admin);
        $raw = (string) $request->cookie(self::TRUST_COOKIE_PREFIX.$admin->getKey(), '');
        if (! $record || ! $record->confirmed_at || ! str_contains($raw, '|')) {
            return false;
        }

        [$expires, $signature] = explode('|', $raw, 2);
        if (! ctype_digit($expires) || (int) $expires < now()->getTimestamp()) {
            return false;
        }

        return hash_equals($this->trustSignature($admin, $record, (int) $expires), $signature);
    }

    // Re-enrolling (new confirmed_at) or a reset invalidates every trusted device.
    private function trustSignature(Admin $admin, AdminTwoFactor $record, int $expires): string
    {
        $payload = $admin->getKey().'|'.$expires.'|'.$record->confirmed_at?->getTimestamp().'|'.$admin->password;

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    // ---------------------------------------------------------------- helpers

    public function audit(Admin $admin, string $action, string $description): void
    {
        ActivityLog::create([
            'admin_id' => $admin->getKey(),
            'action' => $action,
            'description' => $description,
            'timestamp' => now(),
        ]);
    }

    private function normalizeCode(string $code): string
    {
        return preg_replace('/\D/', '', $code) ?? '';
    }

    /** @return array<int, string> */
    private function makeRecoveryCodes(): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $codes = [];
        for ($i = 0; $i < (int) config('two_factor.recovery_codes', 8); $i++) {
            $chars = '';
            for ($j = 0; $j < 10; $j++) {
                $chars .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $codes[] = substr($chars, 0, 5).'-'.substr($chars, 5);
        }

        return $codes;
    }

    private function hashRecoveryCode(string $code): string
    {
        return hash('sha256', Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? ''));
    }
}
