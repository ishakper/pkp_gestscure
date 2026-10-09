<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\PortalAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemAccountController extends Controller
{
    public function __construct(private readonly PortalAccess $portalAccess) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor && ($actor->isSuperAdmin() || $this->portalAccess->can($actor, 'system.view')), 403);

        $accounts = Admin::query()->orderBy('name')->get(['id', 'name', 'email', 'role', 'assigned_building', 'employee_id', 'created_at', 'updated_at']);

        return response()->json(['status' => 'success', 'lifecycle' => 'PLANNED', 'data' => $accounts->map(fn (Admin $account) => [
            'id' => $account->id,
            'name' => $account->name,
            'email' => $account->email,
            'role' => $account->role,
            'assigned_building' => $account->assigned_building,
            'employee_id' => $account->employee_id,
            'status' => null,
            'last_login' => null,
            'created_at' => $account->created_at?->toIso8601String(),
            'updated_at' => $account->updated_at?->toIso8601String(),
        ])]);
    }
}
