<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CardAccessReadService;
use App\Services\PortalAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CardAccessController extends Controller
{
    public function __construct(private readonly CardAccessReadService $service, private readonly PortalAccess $portalAccess) {}
    public function overview(Request $request) { if ($denial = $this->authorizePermission($request, 'credential.view')) return $denial; return response()->json(['data' => $this->service->overview($request->user())]); }
    public function activity(Request $request) { if ($denial = $this->authorizePermission($request, 'credential.view')) return $denial; return response()->json(['data' => $this->service->activity($request->user(), (int) $request->query('limit', 10))]); }
    public function cards(Request $request) { if ($denial = $this->authorizePermission($request, 'credential.view')) return $denial; $page = $this->service->paginateCards($request->user(), $request->query()); return response()->json(['data' => $page->items(), 'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]]); }
    public function employee(Request $request, int $employee) { if ($denial = $this->authorizePermission($request, 'credential.view')) return $denial; return response()->json(['data' => $this->service->detail($request->user(), $employee)]); }
    public function audit(Request $request, int $employee) { if ($denial = $this->authorizePermission($request, 'audit.view')) return $denial; return response()->json(['data' => $this->service->audit($request->user(), $employee)]); }
    private function authorizePermission(Request $request, string $permission): ?JsonResponse { $admin = $request->user(); abort_unless($admin && $this->portalAccess->can($admin, $permission), 403); if ($admin->isBuildingAdmin() && !$admin->employee?->building_id && !$admin->assigned_building) return response()->json(['message' => 'Akun belum memiliki cakupan gedung. Hubungi administrator.'], 403); return null; }
}
