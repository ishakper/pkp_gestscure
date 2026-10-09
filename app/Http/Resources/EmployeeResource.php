<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $doors = $this->relationLoaded('doors') ? $this->doors : $this->doors()->get();
        $method = $this->credential_method ?? 'unknown';
        $status = $this->credential_status ?? 'unknown';
        $hasPersonMapping = trim((string) ($this->hikvision_employee_no ?: $this->source_person_number)) !== '';
        $supportedCredential = in_array($method, ['card', 'fingerprint'], true)
            && in_array($status, ['confirmed_from_backup', 'expected_from_backup', 'verified'], true);
        $legacyCredential = !empty($this->card_no)
            || (bool) optional($this->biometricStatus)->has_fingerprint
            || (bool) optional($this->biometricStatus)->card_enrolled;

        return [
            'id' => $this->id,
            'user_id' => $this->employee_id,
            'employee_id' => $this->employee_id,
            'hikvision_employee_no' => $this->hikvision_employee_no ?: $this->employee_id,
            'nik' => $this->nik,
            'name' => trim((string) $this->name) !== '' ? $this->name : 'Nama belum tersedia',
            'email' => $this->email,
            'phone' => $this->phone,
            'photo_path' => $this->photo_path,
            'credential_method' => $method,
            'credential_status' => $status,
            'credential_source' => $this->credential_source,
            'card_registered' => (bool) $this->card_registered || !empty($this->card_no),
            'card_count' => (int) ($this->card_count ?? 0),
            'card_type' => $this->card_type,
            'fingerprint_verified' => (bool) $this->fingerprint_verified,
            'device_registered' => $hasPersonMapping && ($supportedCredential || $legacyCredential),
            'door_assignment_count' => $doors->count(),
            'access_mapping_status' => $doors->isEmpty() ? 'unmapped' : 'mapped',
            'department' => trim((string) $this->department) === '' || $this->department === 'UNASSIGNED'
                ? 'Belum Ditentukan'
                : $this->department,
            'role' => $this->role,
            'role_jabatan' => $this->role_jabatan,
            'employment_type' => $this->employment_type,
            'employment_status' => $this->employment_status,
            'hire_date' => $this->hire_date?->toDateString(),
            'building' => $this->building?->only(['id', 'code', 'name']),
            'division' => $this->division?->only(['id', 'code', 'name']),
            'position' => $this->position?->only(['id', 'code', 'name']),
            'supervisor' => $this->supervisor?->only(['id', 'employee_id', 'name']),
            'biometric_status' => [
                'fingerprint_enrolled' => (bool) optional($this->biometricStatus)->has_fingerprint,
                'card_enrolled' => (bool) optional($this->biometricStatus)->card_enrolled,
            ],
            'door_assign' => $doors->map(fn ($door) => [
                'door_id' => $door->door_id,
                'door_name' => ($door->name ?? $door->door_name)." ({$door->location})",
                'sync_status' => $door->pivot->sync_status ?? 'pending',
                'last_sync_error' => $door->pivot->last_sync_error ?? null,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
