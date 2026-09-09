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
        $token = $admin->createToken('web-session-token')->plainTextToken;
        session(['api_token' => $token]);

        return redirect('/');
    }

    return back()->withErrors(['email' => 'Kredensial login tidak valid.']);
})->middleware('throttle:login');

Route::post('/logout', function (Request $request) {
    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();
    return redirect('/login');
})->name('logout');

Route::middleware(['auth'])->group(function () {
    Route::get('/', function () {
        $apiToken = session('api_token');
        if (!$apiToken && Auth::check()) {
            $apiToken = Auth::user()->createToken('web-session-token')->plainTextToken;
            session(['api_token' => $apiToken]);
        }
        $admin = Auth::user();
        $doorsQuery = \App\Models\Door::withCount(['employees', 'doorAssignments']);
        if ($admin && $admin->isBuildingAdmin() && $admin->assigned_building) {
            $doorsQuery->where('location', $admin->assigned_building);
        }
        $doors = $doorsQuery->get();

        return view('dashboard', [
            'admin' => $admin,
            'apiToken' => $apiToken,
            'doors' => $doors,
        ]);
    });

    // Web routes
    Route::get('/live-stream', [\App\Http\Controllers\LiveAccessStreamController::class, 'stream']);
});

