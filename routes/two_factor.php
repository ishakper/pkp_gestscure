<?php

use App\Http\Controllers\TwoFactor\TwoFactorController;
use Illuminate\Support\Facades\Route;

// Two-factor authentication pages. Loaded by TwoFactorServiceProvider inside the
// `web` middleware group; routes/web.php is not modified.

Route::middleware('throttle:two-factor')->group(function () {
    Route::get('/two-factor/challenge', [TwoFactorController::class, 'showChallenge']);
    Route::post('/two-factor/challenge', [TwoFactorController::class, 'challenge']);
    Route::get('/two-factor/setup', [TwoFactorController::class, 'showSetup']);
    Route::post('/two-factor/setup', [TwoFactorController::class, 'setup']);
    Route::post('/two-factor/cancel', [TwoFactorController::class, 'cancel']);
});

Route::middleware('auth')->group(function () {
    Route::get('/two-factor/recovery-codes', [TwoFactorController::class, 'showRecoveryCodes']);
    Route::get('/account/security', [TwoFactorController::class, 'account']);
    Route::post('/account/security/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])->middleware('throttle:two-factor');
    Route::post('/account/security/disable', [TwoFactorController::class, 'disable'])->middleware('throttle:two-factor');
});
