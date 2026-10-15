<?php

namespace App\Http\Resources;

use App\Models\Building;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DoorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $inferredBuildings = $this->building_id || !$this->location ? collect() : Building::query()
            ->where(fn ($query) => $query->where('name', $this->location)->orWhere('code', $this->location))
            ->get(['id', 'name']);
        $inferredBuilding = $inferredBuildings->count() === 1 ? $inferredBuildings->first() : null;

        return [
            'id' => $this->id,
            'door_id' => $this->door_id,
            'door_name' => $this->door_name ?? $this->name,
            'location' => $this->location,
            'building_id' => $this->building_id,
            'building_name' => $this->building?->name,
            'building_inferred_id' => $inferredBuilding?->id,
            'building_inferred_name' => $inferredBuilding?->name,
            'building_inference_ambiguous' => $inferredBuildings->count() > 1,
            'zone_id' => $this->zone_id,
            'device_ip' => $this->device_ip ?? $this->ip_address,
            'gateway' => $this->gateway,
            'device_model' => $this->device_model ?? $this->model,
            'connection_status' => $this->connection_status ?? $this->status,
            'health_status' => $this->health_status ?: (($this->connection_status ?? $this->status) === 'online' ? 'online' : 'offline'),
            'total_assigned_users' => (int) ($this->employees_count ?? $this->door_assignments_count ?? $this->employees()->count()),
            'event_count' => (int) ($this->access_logs_count ?? $this->accessLogs()->count()),
            'is_manual_override' => (bool) $this->is_manual_override,
            'last_checked_at' => $this->last_checked_at ? $this->last_checked_at->toIso8601String() : null,
        ];
    }
}
