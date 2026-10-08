<?php

// Two-factor authentication (TOTP) for SecureGate admin logins.
// Implemented as an add-on layer (App\Providers\TwoFactorServiceProvider): the existing
// login routes, AuthController and admins table are not modified.
return [
    // Master switch. When false, login behaves exactly as before.
    'enabled' => (bool) env('TWO_FACTOR_ENABLED', false),

    // Roles that must use 2FA once the switch is on. Other roles may enrol voluntarily.
    'required_roles' => array_values(array_filter(array_map('trim', explode(',', (string) env('TWO_FACTOR_REQUIRED_ROLES', 'super_admin'))))),

    // Label shown in the authenticator app.
    'issuer' => env('TWO_FACTOR_ISSUER', 'PKP SecureGate'),

    // "Ingat perangkat ini" duration; 0 disables the option.
    'remember_days' => (int) env('TWO_FACTOR_REMEMBER_DAYS', 30),

    // How long a password-verified login may wait for its 2FA code.
    'pending_minutes' => 5,

    // Wrong codes in a row before the account's 2FA is locked, and for how long.
    'max_attempts' => (int) env('TWO_FACTOR_MAX_ATTEMPTS', 5),
    'lockout_minutes' => (int) env('TWO_FACTOR_LOCKOUT_MINUTES', 15),

    'recovery_codes' => 8,
];
