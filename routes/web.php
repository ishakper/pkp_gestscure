<?php

use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes (Web Application Dashboard Interface)
|--------------------------------------------------------------------------
*/

Route::get('/login', function () {
    if (Auth::check()) {
        return redirect('/');
    }
    return view('login');
})->name('login');

Route::post('/login', function (Request $request) {
    $request->validate([
        'email' => 'required|email',
        'password' => 'required',
    ]);

    $admin = Admin::where('email', $request->email)->first();

    if ($admin && Hash::check($request->password, $admin->password)) {
        Auth::login($admin);
        $request->session()->regenerate();
        $token = $admin->createToken('web-session-token');
        $request->session()->put([
            'api_token' => $token->plainTextToken,
            'api_token_id' => $token->accessToken->getKey(),
        ]);

        return redirect('/');
    }

    return back()->withErrors(['email' => 'Kredensial login tidak valid.']);
})->middleware('throttle:login');

Route::post('/logout', function (Request $request) {
    $tokenId = $request->session()->get('api_token_id');
    if ($tokenId) {
        $request->user()?->tokens()->whereKey($tokenId)->where('name', 'web-session-token')->delete();
    }
    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();
    return redirect('/login');
})->name('logout');

Route::middleware(['auth'])->group(function () {
    Route::get('/', function () {
        $apiToken = session('api_token');
        $admin = Auth::user();
        $doorsQuery = \App\Models\Door::withCount(['employees', 'doorAssignments']);
        if ($admin && $admin->isBuildingAdmin() && $admin->assigned_building) {
            $doorsQuery->where('location', $admin->assigned_building);
        }
        $doors = $doorsQuery->get();

        $portalAccess = app(\App\Services\PortalAccess::class);
        return view('dashboard', [
            'admin' => $admin,
            'portal' => $portalAccess->portalFor($admin),
            'permissions' => $portalAccess->permissionsFor($admin),
            'apiToken' => $apiToken,
            'doors' => $doors,
        ]);
    });

    // Web routes
    Route::get('/live-stream', [\App\Http\Controllers\LiveAccessStreamController::class, 'stream']);
});
