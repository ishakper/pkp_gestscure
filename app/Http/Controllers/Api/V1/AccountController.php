<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class AccountController extends Controller
{
    #[OA\Get(
        path: '/accounts',
        summary: 'Daftar Akun Sistem',
        description: 'Mendapatkan daftar seluruh akun administrator / pengguna sistem PKP Secure Gate.',
        tags: ['System Accounts'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Daftar akun sistem berhasil didapatkan'
            )
        ]
    )]
    public function index(Request $request)
    {
        $accounts = Admin::with('employee:id,name,employee_code,department,designation')
            ->orderBy('id', 'asc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $accounts->map(function ($acc) {
                return [
                    'id' => $acc->id,
                    'name' => $acc->name,
                    'email' => $acc->email,
                    'role' => $acc->role,
                    'assigned_building' => $acc->assigned_building,
                    'must_change_password' => (bool)$acc->must_change_password,
                    'employee' => $acc->employee ? [
                        'id' => $acc->employee->id,
                        'name' => $acc->employee->name,
                        'employee_code' => $acc->employee->employee_code,
                        'department' => $acc->employee->department,
                        'designation' => $acc->employee->designation,
                    ] : null,
                    'created_at' => $acc->created_at ? $acc->created_at->toDateTimeString() : null,
                ];
            }),
        ]);
    }

    #[OA\Patch(
        path: '/accounts/{id}/password',
        summary: 'Reset Password Akun Sistem',
        description: 'Authorized Admin me-reset password akun target, meng-generate password sementara, menandai must_change_password = true, dan mencabut seluruh token Sanctum aktif milik pengguna target.',
        tags: ['System Accounts'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'ID Akun Admin target',
                schema: new OA\Schema(type: 'integer')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Password akun berhasil di-reset'
            ),
            new OA\Response(
                response: 403,
                description: 'Akses ditolak'
            ),
            new OA\Response(
                response: 404,
                description: 'Akun tidak ditemukan'
            )
        ]
    )]
    public function resetPassword(Request $request, $id)
    {
        $user = $request->user();

        // Enforce RBAC protection
        if (!$user->isSuperAdmin() && !$user->hasPermission('user.manage') && !$user->hasPermission('organization.manage')) {
            return response()->json([
                'status' => 'error',
                'code' => 403,
                'message' => 'Anda tidak memiliki hak akses untuk me-reset password akun sistem.',
            ], 403);
        }

        $targetAdmin = Admin::find($id);
        if (!$targetAdmin) {
            return response()->json([
                'status' => 'error',
                'code' => 404,
                'message' => 'Akun sistem tidak ditemukan.',
            ], 404);
        }

        // Generate temporary password
        $tempPassword = 'Pass-' . Str::random(8);

        // Update target admin password and flag
        $targetAdmin->password = Hash::make($tempPassword);
        $targetAdmin->must_change_password = true;
        $targetAdmin->save();

        // Revoke ALL active Sanctum tokens of target user only
        $targetAdmin->tokens()->delete();

        // Audit log without plain password
        ActivityLog::create([
            'admin_id' => $user->id,
            'action' => 'admin_password_reset',
            'description' => "Password for admin account {$targetAdmin->email} (ID: {$targetAdmin->id}) was reset by Admin {$user->name}",
            'timestamp' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Password untuk akun {$targetAdmin->email} berhasil di-reset. Seluruh sesi aktif pengguna ini telah dicabut.",
            'data' => [
                'account_id' => $targetAdmin->id,
                'name' => $targetAdmin->name,
                'email' => $targetAdmin->email,
                'temporary_password' => $tempPassword,
                'must_change_password' => true,
            ],
        ]);
    }
}
