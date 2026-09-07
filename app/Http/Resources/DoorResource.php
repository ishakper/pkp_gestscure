<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DoorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'door_id' => $this->door_id,
            'door_name' => $this->door_name ?? $this->name,
            'location' => $this->location,
            'device_ip' => $this->device_ip ?? $this->ip_address,
            'device_model' => $this->device_model ?? $this->model,
            'connection_status' => $this->connection_status ?? $this->status,
            'total_assigned_users' => (int) ($this->employees_count ?? $this->door_assignments_count ?? $this->employees()->count()),
            'is_manual_override' => (bool) $this->is_manual_override,
            'last_checked_at' => $this->last_checked_at ? $this->last_checked_at->toIso8601String() : null,
        ];
    }
}
