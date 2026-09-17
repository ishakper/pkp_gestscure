<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\ActivityLog;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use OpenApi\Attributes as OA;

class AuthController extends Controller
{
    #[OA\Post(
        path: '/auth/login',
        summary: 'Autentikasi Pengguna / Admin',
        description: 'Melakukan login admin/pengguna dan mengembalikan Sanctum Bearer token.',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@example.com'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: '********')
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Login berhasil',
                content: new OA\JsonContent(
                    example: [
                        'status' => 'success',
                        'message' => 'Login berhasil',
                        'data' => [
                            'token' => '<redacted_bearer_token>',
                            'token_type' => 'Bearer',
                            'abilities' => ['*'],
                            'admin' => [
                                'id' => 1,
                                'name' => 'Super Admin',
                                'email' => 'admin@example.com',
                                'role' => 'super_admin',
                                'assigned_building' => null
                            ]
                        ]
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Kredensial login tidak valid',
                content: new OA\JsonContent(
                    example: [
                        'status' => 'error',
                        'code' => 401,
                        'message' => 'Kredensial login tidak valid (Email atau Password salah)'
                    ]
                )
            )
        ]
    )]
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

    #[OA\Post(
        path: '/auth/device-token',
        summary: 'Generate Token Terminal Hardware',
        description: 'Hanya Super Admin yang berwenang men-generate token terminal hardware dengan scope khusus [device:push-log].',
        tags: ['Auth'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['door_id'],
                properties: [
                    new OA\Property(property: 'door_id', type: 'string', example: 'DOOR-001')
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Token perangkat berhasil di-generate',
                content: new OA\JsonContent(
                    example: [
                        'status' => 'success',
                        'message' => 'Token perangkat untuk terminal DOOR-001 berhasil di-generate.',
                        'data' => [
                            'door_id' => 'DOOR-001',
                            'door_name' => 'Pintu Utama Server',
                            'token' => '<redacted_device_token>',
                            'token_type' => 'Bearer',
                            'abilities' => ['device:push-log']
                        ]
                    ]
                )
            ),
            new OA\Response(
                response: 403,
                description: 'Akses ditolak / Hak akses tidak mencukupi'
            )
        ]
    )]
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

    #[OA\Get(
        path: '/auth/me',
        summary: 'Profil Pengguna Aktif',
        description: 'Mendapatkan profil informasi pengguna yang sedang tersambung.',
        tags: ['Auth'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Data profil berhasil didapatkan',
                content: new OA\JsonContent(
                    example: [
                        'status' => 'success',
                        'data' => [
                            'id' => 1,
                            'name' => 'Super Admin',
                            'email' => 'admin@example.com',
                            'role' => 'super_admin',
                            'assigned_building' => null
                        ]
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated')
        ]
    )]
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

    #[OA\Post(
        path: '/auth/logout',
        summary: 'Logout Pengguna',
        description: 'Menghapus token akses Sanctum pengguna aktif.',
        tags: ['Auth'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Logout berhasil',
                content: new OA\JsonContent(
                    example: [
                        'status' => 'success',
                        'message' => 'Logout berhasil'
                    ]
                )
            )
        ]
    )]
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
