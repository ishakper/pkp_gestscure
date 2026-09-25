<?php

namespace App\Http\Controllers\Web;

use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController
{
    public function showLogin()
    {
        if (Auth::check()) {
            return redirect('/');
        }
        return view('login');
    }

    public function handleLogin(Request $request)
    {
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
    }

    public function handleLogout(Request $request)
    {
        $tokenId = $request->session()->get('api_token_id');
        if ($tokenId) {
            $request->user()?->tokens()->whereKey($tokenId)->where('name', 'web-session-token')->delete();
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/login');
    }
}
