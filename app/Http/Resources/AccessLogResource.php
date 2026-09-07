<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccessLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'log_id' => $this->log_id,
            'door_id' => $this->door->door_id ?? $this->door_id,
            'door_name' => $this->door ? ($this->door->door_name ?? $this->door->name) : null,
            'device_ip' => $this->device_ip ?? ($this->door->device_ip ?? $this->door->ip_address ?? null),
            'user' => $this->employee ? [
                'nik' => $this->employee->nik,
                'name' => $this->employee->name,
                'department' => $this->employee->department,
            ] : ($this->nik ? [
                'nik' => $this->nik,
                'name' => null,
                'department' => null,
            ] : null),
            'verify_method' => $this->verify_method ?? $this->auth_method,
            'access_status' => $this->access_status ?? $this->status,
            'reason' => $this->reason,
            'timestamp' => $this->timestamp ? $this->timestamp->toIso8601String() : ($this->scanned_at ? $this->scanned_at->toIso8601String() : null),
        ];
    }
}
