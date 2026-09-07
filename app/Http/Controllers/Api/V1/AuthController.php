<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\ActivityLog;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(LoginRequest $request)
    {
        $admin = Admin::where('email', $request->email)->first();

        if (!$admin || !Hash::check($request->password, $admin->password)) {
            return response()->json([
                'status' => 'error',
                'code' => 401,
                'message' => 'Kredensial login tidak valid (Email atau Password salah)',
            ], 401);
        }

        // Admin token receives full abilities
        $token = $admin->createToken('dashboard-token', ['*'])->plainTextToken;

        ActivityLog::create([
            'admin_id' => $admin->id,
            'action' => 'admin_login',
            'description' => "Admin {$admin->name} ({$admin->email}) login to dashboard",
            'timestamp' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Login berhasil',
            'data' => [
                'token' => $token,
                'token_type' => 'Bearer',
                'abilities' => ['*'],
                'admin' => [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                    'role' => $admin->role,
                    'assigned_building' => $admin->assigned_building,
                ],
            ],
        ]);
    }

    public function issueDeviceToken(Request $request)
    {
        $request->validate([
            'door_id' => 'required|string|exists:doors,door_id',
        ]);

        $admin = $request->user();
        if (!$admin || !$admin->isSuperAdmin()) {
            return response()->json([
                'status' => 'error',
                'code' => 403,
                'message' => 'Hanya Super Admin yang berwenang men-generate token terminal hardware.',
            ], 403);
        }

        $door = \App\Models\Door::where('door_id', $request->door_id)->firstOrFail();

        // Hardware device tokens are strictly restricted to ['device:push-log']
        $token = $admin->createToken("device-token-{$door->door_id}", ['device:push-log'])->plainTextToken;

        ActivityLog::create([
            'admin_id' => $admin->id,
            'action' => 'issue_device_token',
            'description' => "Super Admin {$admin->name} issued restricted device token for terminal {$door->door_id} with scope ['device:push-log']",
            'timestamp' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Token perangkat untuk terminal {$door->door_id} berhasil di-generate.",
            'data' => [
                'door_id' => $door->door_id,
                'door_name' => $door->door_name,
                'token' => $token,
                'token_type' => 'Bearer',
                'abilities' => ['device:push-log'],
            ],
        ], 201);
    }

    public function me(Request $request)
    {
        $admin = $request->user();

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => $admin->role,
                'assigned_building' => $admin->assigned_building,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $admin = $request->user();
        if ($admin && $admin->currentAccessToken()) {
            $admin->currentAccessToken()->delete();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Logout berhasil',
        ]);
    }
}
