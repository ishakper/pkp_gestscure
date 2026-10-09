# Two-Factor Authentication (TOTP) for Admin Login

Added as a separate layer. **Not modified:** `routes/web.php` (POST /login), `Api\V1\AuthController`,
`Admin` model, `admins` table, `login.blade.php`, `dashboard.blade.php`, `app/Http/Kernel.php`.
The only touched core line is the provider registration in `config/app.php`.

## How it works

| Piece | File |
|---|---|
| Switch & settings | `config/two_factor.php` (`TWO_FACTOR_*` env) |
| Data (separate table) | `admin_two_factor` — encrypted secret, SHA-256-hashed recovery codes, lockout counters |
| Rules | `app/Services/TwoFactor/TwoFactorService.php` |
| Interception | `app/Http/Middleware/EnforceTwoFactor.php`, appended to the `web` + `api` groups by `app/Providers/TwoFactorServiceProvider.php` |
| Pages | `routes/two_factor.php`, `app/Http/Controllers/TwoFactor/TwoFactorController.php`, `resources/views/two-factor/*`, `public/css/two-factor.css` |
| Emergency reset | `php artisan securegate:two-factor:reset email@domain` |

Flow with `TWO_FACTOR_ENABLED=true`:

1. **Web login:** if the password is wrong, the original route handles it (same message, same `throttle:login`).
   If it is correct and the role requires 2FA (default `super_admin`) or the admin has enrolled, the session is
   **not** signed in yet: the admin goes to `/two-factor/challenge` (6-digit code or a recovery code, with an optional
   "ingat perangkat ini 30 hari") or, on first use, to `/two-factor/setup` (QR + manual key → 8 recovery codes shown once).
2. **API login** `POST /api/v1/auth/login`: enrolled admins must send `two_factor_code` (or `recovery_code`) in the
   same request. Missing/wrong → `401 {two_factor_required: true}`. Required but not enrolled → `403 {two_factor_setup_required: true}`.
   Locked → `423`.
3. **Sessions opened before the switch** are redirected to the challenge on their next page.
4. **Lockout:** 5 wrong codes → 2FA locked 15 minutes (web + API). Password brute force stays on the existing limiter,
   and the interception path counts against the same `login` limiter.
5. Codes cannot be replayed (last used time-step is stored). A trusted-device cookie is HMAC-signed and becomes invalid
   when 2FA is reset/re-enrolled or the password changes.
6. Everything is written to the Audit Log (`two_factor_*` actions).

Account page: `/account/security` — status, regenerate recovery codes, disable (only for roles where 2FA is optional).

## Deploy

1. `composer install` (adds `pragmarx/google2fa`, `bacon/bacon-qr-code`).
2. `php artisan migrate` (creates `admin_two_factor` only).
3. Deploy with `TWO_FACTOR_ENABLED=false` first → nothing changes.
4. Set `TWO_FACTOR_ENABLED=true`, then `php artisan config:cache && php artisan route:cache`.
5. Each Super Admin logs in once and scans the QR. Store the recovery codes safely.

Server clock must be correct (NTP) — TOTP accepts ±30 seconds.

**Rollback:** set `TWO_FACTOR_ENABLED=false` and re-cache config. Data stays; nothing else is affected.

**Lost phone and lost recovery codes:** an operator with server access runs
`php artisan securegate:two-factor:reset admin@domain --force`; the admin sets 2FA up again at the next login.

## Notes

- API clients that log in as a Super Admin (scripts, Postman, mobile) must send `two_factor_code` once 2FA is on.
- The login page still pre-fills demo credentials (`admin@accesscontrol.local` / `password`). That is outside this
  change, but it should be removed for production.

Tests: `tests/Feature/TwoFactorAuthTest.php`.
