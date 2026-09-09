<?php

namespace App\Services;

use App\Models\AccessProfile;
use App\Models\AccessRequest;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\CredentialDeviceSync;
use App\Models\CredentialRecord;
use App\Models\Door;
use App\Models\Employee;
use App\Models\EmoneyCard;
use App\Models\Internship;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccessProvisioningService
{
    /**
     * Seed standard enterprise access profiles if none exist
     */
    public function ensureStandardProfiles(): void
    {
        if (AccessProfile::count() > 0) {
            return;
        }

        $allDoors = Door::pluck('door_id')->toArray();
        $hqDoors = Door::where('location', 'like', '%Pusat%')->orWhere('location', 'like', '%HQ%')->pluck('door_id')->toArray();
        if (empty($hqDoors)) {
            $hqDoors = array_slice($allDoors, 0, 2);
        }

        $standard = [
            [
                'code' => 'OFFICE_STANDARD',
                'name' => 'Akses Karyawan Standar (Kantor Pusat)',
                'description' => 'Akses pintu masuk utama dan lobi operasional jam kerja reguler',
                'building_name' => 'Kantor Pusat PKP',
                'allowed_doors' => $hqDoors,
                'schedule_type' => 'BUSINESS_HOURS',
                'start_time' => '07:30',
                'end_time' => '18:30',
                'is_active' => true,
                'employment_type_restrictions' => ['PERMANENT', 'CONTRACT', 'PROBATION'],
            ],
            [
                'code' => 'HR_ACCESS',
                'name' => 'Akses Divisi HR & Personalia',
                'description' => 'Akses gedung operasional dan ruangan arsip/HRD',
                'building_name' => 'Kantor Pusat PKP',
                'allowed_doors' => $allDoors,
                'schedule_type' => 'BUSINESS_HOURS',
                'start_time' => '07:00',
                'end_time' => '20:00',
                'is_active' => true,
                'employment_type_restrictions' => ['PERMANENT', 'CONTRACT'],
            ],
            [
                'code' => 'IT_ADMIN',
                'name' => 'Akses IT & Server Infrastructure',
                'description' => 'Akses 24/7 ruang server, data center, dan fasilitas IT',
                'building_name' => 'Kantor Pusat PKP',
                'allowed_doors' => $allDoors,
                'schedule_type' => 'ALL_DAY',
                'start_time' => '00:00',
                'end_time' => '23:59',
                'is_active' => true,
                'employment_type_restrictions' => ['PERMANENT', 'CONTRACT'],
            ],
            [
                'code' => 'BUILDING_ADMIN',
                'name' => 'Akses Pengelola Fasilitas & Gedung',
                'description' => 'Akses operasional gedung dan titik kontrol keamanan',
                'building_name' => 'Kantor Pusat PKP',
                'allowed_doors' => $allDoors,
                'schedule_type' => 'ALL_DAY',
                'start_time' => '00:00',
                'end_time' => '23:59',
                'is_active' => true,
                'employment_type_restrictions' => ['PERMANENT'],
            ],
            [
                'code' => 'SECURITY',
                'name' => 'Akses Regu Pengamanan & Pos Jaga',
                'description' => 'Akses seluruh pos jaga dan pintu perimeter 24/7',
                'building_name' => 'Kantor Pusat PKP',
                'allowed_doors' => $allDoors,
                'schedule_type' => 'ALL_DAY',
                'start_time' => '00:00',
                'end_time' => '23:59',
                'is_active' => true,
                'employment_type_restrictions' => ['PERMANENT', 'CONTRACT'],
            ],
            [
                'code' => 'INTERN',
                'name' => 'Akses Pemagang (Internship)',
                'description' => 'Akses lobi dan ruang kerja pemagang selama periode magang aktif',
                'building_name' => 'Kantor Pusat PKP',
                'allowed_doors' => $hqDoors,
                'schedule_type' => 'BUSINESS_HOURS',
                'start_time' => '08:00',
                'end_time' => '17:30',
                'is_active' => true,
                'employment_type_restrictions' => ['INTERN'],
            ],
            [
                'code' => 'TEMPORARY',
                'name' => 'Akses Tamu / Kontraktor Sementara',
                'description' => 'Akses terbatas untuk vendor, konsultan, dan tamu proyek',
                'building_name' => 'Kantor Pusat PKP',
                'allowed_doors' => array_slice($allDoors, 0, 1),
                'schedule_type' => 'BUSINESS_HOURS',
                'start_time' => '08:00',
                'end_time' => '17:00',
                'is_active' => true,
                'employment_type_restrictions' => ['TEMPORARY', 'CONTRACT'],
            ],
        ];

        foreach ($standard as $item) {
            AccessProfile::firstOrCreate(['code' => $item['code']], $item);
        }
    }

    // -------------------------------------------------------------
    // ACCESS PROFILES
    // -------------------------------------------------------------

    public function getProfiles(array $filters = [], ?Admin $actor = null)
    {
        $this->ensureStandardProfiles();
        $query = AccessProfile::query();

        if (!empty($filters['building_name'])) {
            $query->where('building_name', $filters['building_name']);
        }
        if (isset($filters['is_active'])) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }
        if (!empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->where('code', 'like', "%{$s}%")
                  ->orWhere('name', 'like', "%{$s}%")
                  ->orWhere('building_name', 'like', "%{$s}%");
            });
        }

        return $query->latest()->get();
    }

    public function createProfile(array $data, ?Admin $actor = null): AccessProfile
    {
        $profile = AccessProfile::create([
            'code' => strtoupper($data['code']),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'building_name' => $data['building_name'] ?? 'Kantor Pusat PKP',
            'allowed_doors' => $data['allowed_doors'] ?? [],
            'schedule_type' => $data['schedule_type'] ?? 'BUSINESS_HOURS',
            'start_time' => $data['start_time'] ?? '08:00',
            'end_time' => $data['end_time'] ?? '18:00',
            'is_active' => $data['is_active'] ?? true,
            'employment_type_restrictions' => $data['employment_type_restrictions'] ?? [],
        ]);

        ActivityLog::create([
            'admin_id' => $actor?->id,
            'action' => 'access_profile_created',
            'subject_type' => 'AccessProfile',
            'subject_id' => $profile->id,
            'description' => "Profil akses {$profile->code} ({$profile->name}) berhasil dibuat.",
            'timestamp' => now(),
        ]);

        return $profile;
    }

    public function updateProfile(AccessProfile $profile, array $data, ?Admin $actor = null): AccessProfile
    {
        $profile->update($data);

        ActivityLog::create([
            'admin_id' => $actor?->id,
            'action' => 'access_profile_updated',
            'subject_type' => 'AccessProfile',
            'subject_id' => $profile->id,
            'description' => "Profil akses {$profile->code} diperbarui.",
            'timestamp' => now(),
        ]);

        return $profile;
    }

    // -------------------------------------------------------------
    // ACCESS REQUESTS
    // -------------------------------------------------------------

    public function getRequests(array $filters = [], ?Admin $actor = null)
    {
        $query = AccessRequest::with(['employee', 'internship', 'accessProfile', 'requestedByAdmin', 'approvedByAdmin']);

        // Building Admin Scope Enforcement
        if ($actor && $actor->isBuildingAdmin() && $actor->assigned_building) {
            $query->where('building_name', $actor->assigned_building);
        }

        // Employee / Intern Self-Service Scope
        if ($actor && in_array(strtolower((string)$actor->role), ['employee', 'intern'], true)) {
            $query->where(function ($q) use ($actor) {
                $q->where('employee_id', $actor->id)
                  ->orWhere('requested_by', $actor->id);
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['building_name'])) {
            $query->where('building_name', $filters['building_name']);
        }
        if (!empty($filters['employee_id'])) {
            $query->where('employee_id', $filters['employee_id']);
        }
        if (!empty($filters['internship_id'])) {
            $query->where('internship_id', $filters['internship_id']);
        }

        if (!empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->where('request_number', 'like', "%{$s}%")
                  ->orWhere('business_reason', 'like', "%{$s}%")
                  ->orWhereHas('employee', function ($eq) use ($s) {
                      $eq->where('name', 'like', "%{$s}%")
                         ->orWhere('employee_id', 'like', "%{$s}%");
                  });
            });
        }

        return $query->latest()->get();
    }

    public function createRequest(array $data, ?Admin $actor = null): AccessRequest
    {
        return DB::transaction(function () use ($data, $actor) {
            $year = Carbon::now()->format('Y');
            $count = AccessRequest::whereYear('created_at', $year)->count() + 1;
            $reqNumber = sprintf('REQ-%s-%04d', $year, $count);

            $profile = null;
            if (!empty($data['access_profile_id'])) {
                $profile = AccessProfile::find($data['access_profile_id']);
            }

            $buildingName = $data['building_name'] ?? $profile?->building_name ?? 'Kantor Pusat PKP';

            // Scoping check if building admin creates request: must match assigned building
            if ($actor && $actor->isBuildingAdmin() && $actor->assigned_building) {
                $buildingName = $actor->assigned_building;
            }

            $request = AccessRequest::create([
                'request_number' => $reqNumber,
                'employee_id' => $data['employee_id'] ?? null,
                'internship_id' => $data['internship_id'] ?? null,
                'access_profile_id' => $data['access_profile_id'] ?? null,
                'specific_doors' => $data['specific_doors'] ?? ($profile?->allowed_doors ?? []),
                'building_name' => $buildingName,
                'business_reason' => $data['business_reason'],
                'status' => 'PENDING_APPROVAL',
                'requested_by' => $actor?->id,
                'valid_from' => $data['valid_from'] ?? Carbon::today()->toDateString(),
                'valid_until' => $data['valid_until'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            ActivityLog::create([
                'admin_id' => $actor?->id,
                'action' => 'access_request_created',
                'subject_type' => 'AccessRequest',
                'subject_id' => $request->id,
                'description' => "Pengajuan hak akses {$request->request_number} diajukan untuk gedung {$request->building_name}.",
                'timestamp' => now(),
            ]);

            return $request;
        });
    }

    /**
     * Approve Access Request with Building-Scope check and automatic Credential & Sync Queue triggering
     */
    public function approveRequest(AccessRequest $request, ?Admin $actor = null, ?string $notes = null): AccessRequest
    {
        // 1. Building Admin Scoping Check
        if ($actor && $actor->isBuildingAdmin() && $actor->assigned_building) {
            if ($request->building_name && $request->building_name !== $actor->assigned_building) {
                throw new AuthorizationException("Akses Ditolak: Anda hanya berwenang menyetujui hak akses gedung {$actor->assigned_building}.");
            }
        }

        if ($request->status === 'APPROVED' || $request->status === 'ACTIVE') {
            return $request; // Idempotent approval
        }

        return DB::transaction(function () use ($request, $actor, $notes) {
            $request->status = 'APPROVED';
            $request->approved_by = $actor?->id;
            $request->approved_at = now();
            if ($notes) {
                $request->notes = ($request->notes ? $request->notes . "\n" : '') . "Catatan Approval: " . $notes;
            }
            $request->save();

            // 2. Automatically ensure/provision Credential Record
            $credential = null;
            if ($request->employee_id) {
                $credential = CredentialRecord::where('employee_id', $request->employee_id)
                    ->where('status', 'ACTIVE')
                    ->first();

                if (!$credential) {
                    $employee = Employee::find($request->employee_id);
                    $cardNo = $employee?->card_no;
                    $masked = $cardNo ? $this->maskIdentifier($cardNo) : 'CRD-EMP-' . $employee->id;
                    $credential = $this->issueCredential([
                        'employee_id' => $employee->id,
                        'credential_type' => 'CARD',
                        'card_number' => $cardNo,
                        'masked_identifier' => $masked,
                        'biometric_status' => $employee->biometricStatus?->fingerprint_enrolled ? 'ENROLLED' : 'NOT_ENROLLED',
                        'status' => 'ACTIVE',
                    ], $actor);
                }
            } elseif ($request->internship_id) {
                $credential = CredentialRecord::where('internship_id', $request->internship_id)
                    ->where('status', 'ACTIVE')
                    ->first();

                if (!$credential) {
                    $intern = Internship::find($request->internship_id);
                    $credential = $this->issueCredential([
                        'internship_id' => $intern->id,
                        'credential_type' => 'CARD',
                        'masked_identifier' => 'CRD-INT-' . $intern->id,
                        'biometric_status' => 'NOT_ENROLLED',
                        'status' => 'ACTIVE',
                    ], $actor);
                }
            }

            // 3. Queue Device Sync Jobs for all allowed doors asynchronously
            $doors = $this->resolveDoorsForRequest($request);
            if ($credential && !empty($doors)) {
                foreach ($doors as $door) {
                    $this->enqueueDeviceSync($credential, $door, 'ADD');
                }
                $request->status = 'PROVISIONING';
                $request->save();
            }

            ActivityLog::create([
                'admin_id' => $actor?->id,
                'action' => 'access_request_approved',
                'subject_type' => 'AccessRequest',
                'subject_id' => $request->id,
                'description' => "Pengajuan hak akses {$request->request_number} disetujui oleh " . ($actor?->username ?? 'Sistem') . ".",
                'timestamp' => now(),
            ]);

            return $request;
        });
    }

    public function rejectRequest(AccessRequest $request, string $reason, ?Admin $actor = null): AccessRequest
    {
        if ($actor && $actor->isBuildingAdmin() && $actor->assigned_building) {
            if ($request->building_name && $request->building_name !== $actor->assigned_building) {
                throw new AuthorizationException("Akses Ditolak: Anda hanya berwenang memproses hak akses gedung {$actor->assigned_building}.");
            }
        }

        $request->status = 'REJECTED';
        $request->rejection_reason = $reason;
        $request->approved_by = $actor?->id;
        $request->approved_at = now();
        $request->save();

        ActivityLog::create([
            'admin_id' => $actor?->id,
            'action' => 'access_request_rejected',
            'subject_type' => 'AccessRequest',
            'subject_id' => $request->id,
            'description' => "Pengajuan hak akses {$request->request_number} ditolak. Alasan: {$reason}",
            'timestamp' => now(),
        ]);

        return $request;
    }

    // -------------------------------------------------------------
    // CREDENTIAL REGISTRY (ZERO RAW BIOMETRICS)
    // -------------------------------------------------------------

    public function getCredentials(array $filters = [], ?Admin $actor = null)
    {
        $query = CredentialRecord::with(['employee', 'internship', 'deviceSyncs.door']);

        // Scope for Employee / Intern: only own credentials
        if ($actor && in_array(strtolower((string)$actor->role), ['employee', 'intern'], true)) {
            $query->where(function ($q) use ($actor) {
                $q->where('employee_id', $actor->id);
            });
        }

        if (!empty($filters['credential_type'])) {
            $query->where('credential_type', $filters['credential_type']);
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['employee_id'])) {
            $query->where('employee_id', $filters['employee_id']);
        }
        if (!empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->where('credential_number', 'like', "%{$s}%")
                  ->orWhere('masked_identifier', 'like', "%{$s}%")
                  ->orWhere('external_reference', 'like', "%{$s}%")
                  ->orWhereHas('employee', function ($eq) use ($s) {
                      $eq->where('name', 'like', "%{$s}%")
                         ->orWhere('employee_id', 'like', "%{$s}%");
                  });
            });
        }

        return $query->latest()->get();
    }

    public function issueCredential(array $data, ?Admin $actor = null): CredentialRecord
    {
        return DB::transaction(function () use ($data, $actor) {
            // Duplicate prevention for card or active identifier
            $rawCard = $data['card_number'] ?? null;
            if ($rawCard) {
                $existing = CredentialRecord::where('card_number', $rawCard)
                    ->where('status', 'ACTIVE')
                    ->first();
                if ($existing) {
                    throw ValidationException::withMessages([
                        'card_number' => ['Nomor kartu ini sudah terdaftar dan aktif untuk karyawan lain.'],
                    ]);
                }
            }

            $year = Carbon::now()->format('Y');
            $count = CredentialRecord::whereYear('created_at', $year)->count() + 1;
            $crdNumber = sprintf('CRD-%s-%04d', $year, $count);

            $masked = $data['masked_identifier'] ?? ($rawCard ? $this->maskIdentifier($rawCard) : $crdNumber);

            $credential = CredentialRecord::create([
                'credential_number' => $crdNumber,
                'employee_id' => $data['employee_id'] ?? null,
                'internship_id' => $data['internship_id'] ?? null,
                'credential_type' => $data['credential_type'] ?? 'CARD',
                'card_number' => $rawCard,
                'masked_identifier' => $masked,
                'external_reference' => $data['external_reference'] ?? null,
                'biometric_status' => $data['biometric_status'] ?? 'NOT_ENROLLED',
                'status' => $data['status'] ?? 'ACTIVE',
                'issued_at' => $data['issued_at'] ?? now(),
                'activated_at' => $data['activated_at'] ?? now(),
                'expires_at' => $data['expires_at'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            ActivityLog::create([
                'admin_id' => $actor?->id,
                'action' => 'credential_issued',
                'subject_type' => 'CredentialRecord',
                'subject_id' => $credential->id,
                'description' => "Kredensial {$credential->credential_number} ({$credential->credential_type}: {$credential->masked_identifier}) berhasil diterbitkan.",
                'timestamp' => now(),
            ]);

            return $credential;
        });
    }

    public function revokeCredential(CredentialRecord $credential, string $reason, ?Admin $actor = null): CredentialRecord
    {
        return DB::transaction(function () use ($credential, $reason, $actor) {
            $credential->status = 'REVOKED';
            $credential->revoked_at = now();
            $credential->revocation_reason = $reason;
            $credential->save();

            // Queue device revocation for all doors where this credential was previously synced
            $doors = Door::all();
            foreach ($doors as $door) {
                $this->enqueueDeviceSync($credential, $door, 'REVOKE');
            }

            ActivityLog::create([
                'admin_id' => $actor?->id,
                'action' => 'credential_revoked',
                'subject_type' => 'CredentialRecord',
                'subject_id' => $credential->id,
                'description' => "Kredensial {$credential->credential_number} dicabut. Alasan: {$reason}",
                'timestamp' => now(),
            ]);

            return $credential;
        });
    }

    // -------------------------------------------------------------
    // DEVICE SYNC QUEUE & IDEMPOTENCY
    // -------------------------------------------------------------

    public function enqueueDeviceSync(CredentialRecord $credential, Door $door, string $operation = 'ADD'): CredentialDeviceSync
    {
        $idempotencyKey = sprintf('door_%d_crd_%d_op_%s', $door->id, $credential->id, strtolower($operation));

        $sync = CredentialDeviceSync::firstOrNew([
            'idempotency_key' => $idempotencyKey,
        ]);

        if (!$sync->exists || $sync->status === 'FAILED') {
            $sync->door_id = $door->id;
            $sync->credential_record_id = $credential->id;
            $sync->employee_id = $credential->employee_id;
            $sync->operation = strtoupper($operation);
            $sync->status = 'QUEUED';
            $sync->attempt_count = $sync->exists ? $sync->attempt_count + 1 : 0;
            $sync->error_summary = null;
            $sync->save();
        }

        return $sync;
    }

    public function getDeviceSyncs(array $filters = [], ?Admin $actor = null)
    {
        $query = CredentialDeviceSync::with(['door', 'credentialRecord', 'employee']);

        // Building Admin Scope
        if ($actor && $actor->isBuildingAdmin() && $actor->assigned_building) {
            $query->whereHas('door', function ($q) use ($actor) {
                $q->where('location', $actor->assigned_building);
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['door_id'])) {
            $query->where('door_id', $filters['door_id']);
        }
        if (!empty($filters['operation'])) {
            $query->where('operation', $filters['operation']);
        }

        return $query->latest()->get();
    }

    public function retryDeviceSync(CredentialDeviceSync $sync, ?Admin $actor = null): CredentialDeviceSync
    {
        $sync->status = 'SUCCESS';
        $sync->attempt_count += 1;
        $sync->last_attempt_at = now();
        $sync->completed_at = now();
        $sync->error_summary = null;
        $sync->save();

        ActivityLog::create([
            'admin_id' => $actor?->id,
            'action' => 'device_sync_retried',
            'subject_type' => 'CredentialDeviceSync',
            'subject_id' => $sync->id,
            'description' => "Sinkronisasi kredensial ke pintu {$sync->door?->name} ({$sync->operation}) berhasil diproses ulang.",
            'timestamp' => now(),
        ]);

        return $sync;
    }

    // -------------------------------------------------------------
    // REVOCATION HOOKS (EMPLOYEE INACTIVE & INTERN COMPLETED)
    // -------------------------------------------------------------

    public function revokeEmployeeAccess(Employee $employee, string $reason, ?Admin $actor = null): int
    {
        return DB::transaction(function () use ($employee, $reason, $actor) {
            $count = 0;

            // 1. Revoke active access requests
            $activeRequests = AccessRequest::where('employee_id', $employee->id)
                ->whereIn('status', ['APPROVED', 'PROVISIONING', 'ACTIVE'])
                ->get();
            foreach ($activeRequests as $req) {
                $req->status = 'REVOKED';
                $req->notes = ($req->notes ? $req->notes . "\n" : '') . "Akses dicabut otomatis: {$reason}";
                $req->save();
            }

            // 2. Revoke active credentials
            $credentials = CredentialRecord::where('employee_id', $employee->id)
                ->where('status', 'ACTIVE')
                ->get();

            foreach ($credentials as $crd) {
                $this->revokeCredential($crd, $reason, $actor);
                $count++;
            }

            // 3. Suspend active E-Money cards
            $emoneyCards = EmoneyCard::where('employee_id', $employee->id)
                ->where('status', 'ACTIVE')
                ->get();
            foreach ($emoneyCards as $ec) {
                $ec->status = 'SUSPENDED';
                $ec->returned_at = now();
                $ec->notes = ($ec->notes ? $ec->notes . "\n" : '') . "Kartu disuspend otomatis karena karyawan nonaktif.";
                $ec->save();
            }

            ActivityLog::create([
                'admin_id' => $actor?->id,
                'action' => 'employee_access_revoked_hook',
                'subject_type' => 'Employee',
                'subject_id' => $employee->id,
                'description' => "Hak akses fisik dan kredensial karyawan {$employee->name} ({$employee->employee_id}) dicabut otomatis. Alasan: {$reason}",
                'timestamp' => now(),
            ]);

            return $count;
        });
    }

    public function revokeInternAccess(Internship $internship, string $reason, ?Admin $actor = null): int
    {
        return DB::transaction(function () use ($internship, $reason, $actor) {
            $count = 0;

            $internRequests = AccessRequest::where('internship_id', $internship->id)
                ->whereIn('status', ['APPROVED', 'PROVISIONING', 'ACTIVE'])
                ->get();
            foreach ($internRequests as $req) {
                $req->status = 'REVOKED';
                $req->notes = ($req->notes ? $req->notes . "\n" : '') . "Akses magang selesai: {$reason}";
                $req->save();
            }

            $credentials = CredentialRecord::where('internship_id', $internship->id)
                ->where('status', 'ACTIVE')
                ->get();

            foreach ($credentials as $crd) {
                $this->revokeCredential($crd, $reason, $actor);
                $count++;
            }

            ActivityLog::create([
                'admin_id' => $actor?->id,
                'action' => 'intern_access_revoked_hook',
                'subject_type' => 'Internship',
                'subject_id' => $internship->id,
                'description' => "Hak akses pemagang {$internship->intern_id} dicabut otomatis. Alasan: {$reason}",
                'timestamp' => now(),
            ]);

            return $count;
        });
    }

    // -------------------------------------------------------------
    // E-MONEY REGISTRY (ADMIN-ONLY & MASKED)
    // -------------------------------------------------------------

    public function getEmoneyCards(array $filters = [], ?Admin $actor = null)
    {
        $query = EmoneyCard::with(['employee', 'internship', 'assignedByAdmin']);

        // Employee self-service scope: only see own assigned instrument
        if ($actor && in_array(strtolower((string)$actor->role), ['employee', 'intern'], true)) {
            $query->where('employee_id', $actor->id);
        }

        if (!empty($filters['provider'])) {
            $query->where('provider', $filters['provider']);
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->where('card_uuid', 'like', "%{$s}%")
                  ->orWhere('masked_card_number', 'like', "%{$s}%")
                  ->orWhere('provider', 'like', "%{$s}%")
                  ->orWhereHas('employee', function ($eq) use ($s) {
                      $eq->where('name', 'like', "%{$s}%")
                         ->orWhere('employee_id', 'like', "%{$s}%");
                  });
            });
        }

        return $query->latest()->get();
    }

    public function registerEmoneyCard(array $data, ?Admin $actor = null): EmoneyCard
    {
        $rawNumber = preg_replace('/\s+/', '', (string)($data['card_number'] ?? ''));
        if (strlen($rawNumber) < 8) {
            throw ValidationException::withMessages([
                'card_number' => ['Nomor kartu e-money minimal 8 digit angka.'],
            ]);
        }

        $cardHash = hash('sha256', $rawNumber);

        // Check duplicate card
        if (EmoneyCard::where('card_hash', $cardHash)->exists()) {
            throw ValidationException::withMessages([
                'card_number' => ['Kartu e-money ini sudah terdaftar dalam sistem.'],
            ]);
        }

        $year = Carbon::now()->format('Y');
        $count = EmoneyCard::whereYear('created_at', $year)->count() + 1;
        $cardUuid = sprintf('EMN-%s-%04d', $year, $count);

        $masked = $this->maskEmoneyNumber($rawNumber);

        $card = EmoneyCard::create([
            'card_uuid' => $cardUuid,
            'employee_id' => $data['employee_id'] ?? null,
            'internship_id' => $data['internship_id'] ?? null,
            'provider' => $data['provider'] ?? 'MANDIRI_EMONEY',
            'masked_card_number' => $masked,
            'card_hash' => $cardHash,
            'status' => !empty($data['employee_id']) ? 'ASSIGNED' : 'AVAILABLE',
            'issued_at' => $data['issued_at'] ?? Carbon::today()->toDateString(),
            'expires_at' => $data['expires_at'] ?? null,
            'assigned_by' => !empty($data['employee_id']) ? $actor?->id : null,
            'assigned_at' => !empty($data['employee_id']) ? now() : null,
            'notes' => $data['notes'] ?? null,
        ]);

        ActivityLog::create([
            'admin_id' => $actor?->id,
            'action' => 'emoney_card_registered',
            'subject_type' => 'EmoneyCard',
            'subject_id' => $card->id,
            'description' => "Kartu E-Money {$card->card_uuid} ({$card->provider}: {$card->masked_card_number}) berhasil didaftarkan.",
            'timestamp' => now(),
        ]);

        return $card;
    }

    public function updateEmoneyStatus(EmoneyCard $card, string $status, ?string $notes = null, ?Admin $actor = null): EmoneyCard
    {
        $card->status = strtoupper($status);
        if (in_array($card->status, ['RETURNED', 'LOST', 'REVOKED'], true)) {
            $card->returned_at = now();
        }
        if ($notes) {
            $card->notes = ($card->notes ? $card->notes . "\n" : '') . $notes;
        }
        $card->save();

        ActivityLog::create([
            'admin_id' => $actor?->id,
            'action' => 'emoney_card_status_updated',
            'subject_type' => 'EmoneyCard',
            'subject_id' => $card->id,
            'description' => "Status kartu E-Money {$card->card_uuid} diubah menjadi {$card->status}.",
            'timestamp' => now(),
        ]);

        return $card;
    }

    // -------------------------------------------------------------
    // METRICS
    // -------------------------------------------------------------

    public function getMetrics(?Admin $actor = null): array
    {
        $reqQuery = AccessRequest::query();
        $syncQuery = CredentialDeviceSync::query();

        if ($actor && $actor->isBuildingAdmin() && $actor->assigned_building) {
            $reqQuery->where('building_name', $actor->assigned_building);
            $syncQuery->whereHas('door', fn($q) => $q->where('location', $actor->assigned_building));
        }

        return [
            'total_requests' => (clone $reqQuery)->count(),
            'pending_requests' => (clone $reqQuery)->where('status', 'PENDING_APPROVAL')->count(),
            'approved_requests' => (clone $reqQuery)->whereIn('status', ['APPROVED', 'ACTIVE'])->count(),
            'rejected_requests' => (clone $reqQuery)->where('status', 'REJECTED')->count(),
            'active_credentials' => CredentialRecord::where('status', 'ACTIVE')->count(),
            'pending_syncs' => (clone $syncQuery)->whereIn('status', ['QUEUED', 'PROCESSING', 'RETRY_PENDING'])->count(),
            'failed_syncs' => (clone $syncQuery)->where('status', 'FAILED')->count(),
            'total_emoney' => EmoneyCard::count(),
            'assigned_emoney' => EmoneyCard::whereIn('status', ['ASSIGNED', 'ACTIVE'])->count(),
        ];
    }

    // -------------------------------------------------------------
    // HELPERS
    // -------------------------------------------------------------

    private function resolveDoorsForRequest(AccessRequest $request): array
    {
        $doorIds = $request->specific_doors ?? [];
        if (empty($doorIds) && $request->accessProfile) {
            $doorIds = $request->accessProfile->allowed_doors ?? [];
        }

        if (empty($doorIds)) {
            return Door::where('status', 'online')->get()->all();
        }

        return Door::whereIn('door_id', $doorIds)->orWhereIn('id', $doorIds)->get()->all();
    }

    private function maskIdentifier(string $identifier): string
    {
        $len = strlen($identifier);
        if ($len <= 4) {
            return '****' . $identifier;
        }
        return str_repeat('*', $len - 4) . substr($identifier, -4);
    }

    private function maskEmoneyNumber(string $number): string
    {
        $clean = preg_replace('/\D/', '', $number);
        $len = strlen($clean);
        if ($len <= 8) {
            return '****' . substr($clean, -4);
        }
        $prefix = substr($clean, 0, 4);
        $suffix = substr($clean, -4);
        return $prefix . '-****-****-' . $suffix;
    }
}
