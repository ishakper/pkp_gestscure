<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CardAccessReadService;
use App\Services\PortalAccess;
use Illuminate\Http\Request;

class CardAccessController extends Controller
{
    public function __construct(private readonly CardAccessReadService $service, private readonly PortalAccess $portalAccess) {}
    public function overview(Request $request) { $this->authorizePermission($request, 'credential.view'); return response()->json(['data' => $this->service->overview($request->user())]); }
    public function activity(Request $request) { $this->authorizePermission($request, 'credential.view'); return response()->json(['data' => $this->service->activity($request->user(), (int) $request->query('limit', 10))]); }
    public function cards(Request $request) { $this->authorizePermission($request, 'credential.view'); $page = $this->service->paginateCards($request->user(), $request->query()); return response()->json(['data' => $page->items(), 'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]]); }
    public function accessCatalog(Request $request) { $this->authorizePermission($request, 'credential.view'); return response()->json(['data' => $this->service->accessCatalog($request->user())]); }
    public function accessAssignment(Request $request, int $employee) { $this->authorizePermission($request, 'credential.view'); return response()->json(['data' => $this->service->accessAssignment($request->user(), $employee)]); }
    public function deviceSync(Request $request) { $this->authorizePermission($request, 'credential.view'); $page = $this->service->deviceSync($request->user(), $request->query()); return response()->json(['data' => $page->items(), 'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]]); }
    public function diagnostics(Request $request, int $employee) { $this->authorizePermission($request, 'credential.view'); return response()->json(['data' => $this->service->diagnostics($request->user(), $employee)]); }
    public function employee(Request $request, int $employee) { $this->authorizePermission($request, 'credential.view'); return response()->json(['data' => $this->service->detail($request->user(), $employee)]); }
    public function audit(Request $request, int $employee) { $this->authorizePermission($request, 'audit.view'); return response()->json(['data' => $this->service->audit($request->user(), $employee)]); }
    private function authorizePermission(Request $request, string $permission): void { abort_unless($request->user() && $this->portalAccess->can($request->user(), $permission), 403); }
}
