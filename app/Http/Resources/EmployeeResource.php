<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $doors = $this->relationLoaded('doors') ? $this->doors : $this->doors()->get();

        return [
            'id' => $this->id,
            'user_id' => "USR-{$this->id}",
            'nik' => $this->nik,
            'name' => $this->name,
            'card_no' => $this->card_no,
            'department' => $this->department,
            'role' => $this->role,
            'biometric_status' => [
                'fingerprint_enrolled' => (bool) optional($this->biometricStatus)->has_fingerprint,
                'card_enrolled' => !empty($this->card_no),
            ],
            'door_assign' => $doors->map(function ($door) {
                $doorName = $door->name ?? $door->door_name;
                return [
                    'door_id' => $door->door_id,
                    'door_name' => "{$doorName} ({$door->location})",
                    'sync_status' => $door->pivot->sync_status ?? 'pending',
                    'last_sync_error' => $door->pivot->last_sync_error ?? null,
                ];
            }),
            'created_at' => $this->created_at ? $this->created_at->toIso8601String() : null,
        ];
    }
}
