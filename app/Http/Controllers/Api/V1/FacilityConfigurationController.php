<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Building;
use App\Models\Door;
use App\Models\Zone;
use App\Services\PortalAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FacilityConfigurationController extends Controller
{
    public function __construct(private readonly PortalAccess $portalAccess) {}

    public function buildings(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'organization.view');
        $actor = $request->user();
        $buildings = Building::query()->with([
            'zones' => fn ($query) => $query->orderBy('name'),
            'zones.doors' => fn ($query) => $query->orderBy('name'),
        ]);
        if ($actor->isBuildingAdmin()) {
            $buildings->where(function ($query) use ($actor) {
                $query->whereHas('employees', fn ($employees) => $employees->whereKey($actor->employee_id));
                if ($actor->assigned_building) {
                    $query->orWhere('name', $actor->assigned_building)
                        ->orWhereHas('doors', fn ($doors) => $doors->where('location', $actor->assigned_building));
                }
            });
        }
        return response()->json(['status' => 'success', 'data' => $buildings->orderBy('name')->get(['id', 'code', 'name', 'description', 'is_active'])]);
    }

    public function storeBuilding(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'organization.manage');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/', 'unique:buildings,code'],
            'name' => ['required', 'string', 'max:255', 'unique:buildings,name'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
        $building = Building::create([...$data, 'code' => strtoupper($data['code']), 'is_active' => true]);
        $this->audit($request, 'building_created', 'Building', $building->id, "Building {$building->code} registered");
        return response()->json(['status' => 'success', 'data' => $building], 201);
    }

    public function storeZone(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'organization.manage');
        $request->merge(['code' => strtoupper((string) $request->input('code'))]);
        $data = $request->validate([
            'building_id' => ['required', 'integer', 'exists:buildings,id'],
            'code' => ['required', 'string', 'max:100', 'regex:/^[A-Z0-9_-]+$/', 'unique:zones,code'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        $zone = Zone::create([...$data, 'is_active' => true]);
        $this->audit($request, 'zone_created', 'Zone', $zone->id, "Zone {$zone->code} registered for Building #{$zone->building_id}");
        return response()->json(['status' => 'success', 'data' => $zone], 201);
    }

    public function storeDoor(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'device.manage');
        $data = $this->validateDoor($request);
        $building = Building::findOrFail($data['building_id']);
        $door = Door::create($this->doorPayload($data, $building) + [
            'status' => 'offline', 'connection_status' => 'offline', 'is_manual_override' => false,
        ]);
        $this->audit($request, 'door_created', 'Door', $door->id, "Door {$door->door_id} registered for {$building->name}");
        return response()->json(['status' => 'success', 'message' => 'Terminal registered offline pending connectivity verification.', 'data' => $this->doorData($door)], 201);
    }

    public function updateDoor(Request $request, string $doorId): JsonResponse
    {
        $this->authorizePermission($request, 'device.manage');
        $door = Door::where('door_id', $doorId)->orWhere('id', $doorId)->firstOrFail();
        $data = $this->validateDoor($request, $door);
        $building = Building::findOrFail($data['building_id']);
        $door->update($this->doorPayload($data, $building));
        $this->audit($request, 'door_updated', 'Door', $door->id, "Door {$door->door_id} configuration updated for {$building->name}");
        return response()->json(['status' => 'success', 'data' => $this->doorData($door->fresh())]);
    }

    private function validateDoor(Request $request, ?Door $door = null): array
    {
        return $request->validate([
            'door_id' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('doors', 'door_id')->ignore($door?->id)],
            'name' => ['required', 'string', 'max:120'],
            'building_id' => ['required', 'integer', 'exists:buildings,id'],
            'zone_id' => ['nullable', 'integer', Rule::exists('zones', 'id')->where(fn ($query) => $query->where('building_id', $request->integer('building_id')))],
            'device_ip' => ['required', 'ip', Rule::unique('doors', 'device_ip')->ignore($door?->id)],
            'gateway' => ['nullable', 'ip'],
            'device_model' => ['required', 'string', 'max:120'],
        ]);
    }

    private function doorPayload(array $data, Building $building): array
    {
        return [
            'door_id' => strtoupper($data['door_id']), 'name' => $data['name'],
            'building_id' => $building->id, 'zone_id' => $data['zone_id'] ?? null,
            'location' => $building->name, 'device_ip' => $data['device_ip'],
            'gateway' => $data['gateway'] ?? null, 'device_model' => $data['device_model'],
        ];
    }

    private function doorData(Door $door): array
    {
        $door->load('building:id,name');
        return [
            'id' => $door->id, 'door_id' => $door->door_id, 'door_name' => $door->door_name,
            'building_id' => $door->building_id, 'building_name' => $door->building?->name,
            'location' => $door->location, 'device_ip' => $door->device_ip,
            'gateway' => $door->gateway, 'device_model' => $door->device_model,
            'connection_status' => $door->connection_status,
        ];
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        $actor = $request->user();
        abort_unless($actor && $this->portalAccess->can($actor, $permission), 403);
    }

    private function audit(Request $request, string $action, string $subjectType, int $subjectId, string $description): void
    {
        ActivityLog::create(['admin_id' => $request->user()?->id, 'action' => $action, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'description' => $description, 'timestamp' => now()]);
    }
}
