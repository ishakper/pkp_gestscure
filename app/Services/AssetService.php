<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetCategory;
use App\Models\AssetIncident;
use App\Models\AssetMaintenance;
use App\Models\Employee;
use App\Models\Internship;
use App\Models\OnboardingCase;
use App\Models\OnboardingTask;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssetService
{
    /**
     * Ensure standard enterprise asset categories exist
     */
    public function ensureStandardCategories(): void
    {
        if (AssetCategory::count() > 0) {
            return;
        }

        $categories = [
            ['code' => 'LAPTOP', 'name' => 'Laptop / Notebook', 'useful_life_months' => 36, 'description' => 'Laptop kerja karyawan, MacBook, ThinkPad, Dell Latitude'],
            ['code' => 'DESKTOP', 'name' => 'Desktop PC / Workstation', 'useful_life_months' => 48, 'description' => 'PC unit kantor, server mini, display unit'],
            ['code' => 'MONITOR', 'name' => 'Monitor & Display Eksternal', 'useful_life_months' => 48, 'description' => 'Layar monitor 24-32 inci, display meeting room'],
            ['code' => 'PHONE', 'name' => 'Smartphone & Handphone Dinas', 'useful_life_months' => 24, 'description' => 'Handphone operasional lapangan, supervisor unit'],
            ['code' => 'TABLET', 'name' => 'Tablet & iPad', 'useful_life_months' => 24, 'description' => 'Tablet inspeksi proyek, survei lapangan'],
            ['code' => 'NETWORK_DEVICE', 'name' => 'Perangkat Jaringan & Server', 'useful_life_months' => 60, 'description' => 'Router, switch manageable, access point, firewall'],
            ['code' => 'ACCESS_CONTROL_DEVICE', 'name' => 'Terminal Akses & Smart Lock', 'useful_life_months' => 60, 'description' => 'Hikvision terminal, biometric reader, electromagnetic lock'],
            ['code' => 'PRINTER', 'name' => 'Printer, Scanner & Copier', 'useful_life_months' => 36, 'description' => 'Printer laser, barcode scanner, multi-function scanner'],
            ['code' => 'TOOL', 'name' => 'Peralatan Teknik & Perkakas', 'useful_life_months' => 36, 'description' => 'Multimeter, crimping tool, OTDR, toolkit teknisi'],
            ['code' => 'FURNITURE', 'name' => 'Perabot Kantor & Meja Kerja', 'useful_life_months' => 60, 'description' => 'Kursi ergonomis, meja kerja, filling cabinet'],
            ['code' => 'VEHICLE', 'name' => 'Kendaraan Operasional Perusahaan', 'useful_life_months' => 96, 'description' => 'Mobil dinas, motor kurir, kendaraan proyek'],
            ['code' => 'SAFETY_EQUIPMENT', 'name' => 'Alat Pelindung Diri (APD) & K3', 'useful_life_months' => 24, 'description' => 'Rompi safety, helm proyek, harness, sepatu safety'],
            ['code' => 'OTHER', 'name' => 'Aset Lain-Lain', 'useful_life_months' => 36, 'description' => 'Inventaris umum perusahaan'],
        ];

        foreach ($categories as $cat) {
            AssetCategory::firstOrCreate(['code' => $cat['code']], $cat);
        }
    }

    // -------------------------------------------------------------
    // ASSET MASTER CRUD
    // -------------------------------------------------------------

    public function getAssets(array $filters = [], ?Admin $actor = null)
    {
        $this->ensureStandardCategories();
        $query = Asset::with(['category', 'division', 'currentAssignment.employee', 'currentAssignment.internship']);

        // Building Admin Scope
        if ($actor && $actor->isBuildingAdmin() && $actor->assigned_building) {
            $query->where('building_name', $actor->assigned_building);
        }

        // Employee / Intern Scope: only see assets currently assigned to self
        if ($actor && in_array(strtolower((string)$actor->role), ['employee', 'intern'], true)) {
            $empId = $actor->employee_id ?? $actor->id;
            $query->whereHas('assignments', function ($q) use ($empId) {
                $q->where('employee_id', $empId)
                  ->where('status', 'ACTIVE');
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }
        if (!empty($filters['building_name'])) {
            $query->where('building_name', $filters['building_name']);
        }
        if (!empty($filters['condition'])) {
            $query->where('condition', $filters['condition']);
        }

        if (!empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->where('asset_code', 'like', "%{$s}%")
                  ->orWhere('asset_name', 'like', "%{$s}%")
                  ->orWhere('brand', 'like', "%{$s}%")
                  ->orWhere('model', 'like', "%{$s}%")
                  ->orWhere('serial_number', 'like', "%{$s}%");
            });
        }

        return $query->latest()->get();
    }

    public function getAssetById(int $id, ?Admin $actor = null): Asset
    {
        $asset = Asset::with([
            'category',
            'division',
            'assignments.employee',
            'assignments.internship',
            'assignments.assignedByAdmin',
            'assignments.receivedByAdmin',
            'maintenances.performedByAdmin',
            'incidents.reportedByAdmin',
            'incidents.employee'
        ])->findOrFail($id);

        if ($actor && $actor->isBuildingAdmin() && $actor->assigned_building) {
            if ($asset->building_name && $asset->building_name !== $actor->assigned_building) {
                throw new AuthorizationException("Akses Ditolak: Anda tidak berwenang mengakses data aset gedung {$asset->building_name}.");
            }
        }

        if ($actor && in_array(strtolower((string)$actor->role), ['employee', 'intern'], true)) {
            $empId = $actor->employee_id ?? $actor->id;
            $hasAssignment = $asset->assignments->where('employee_id', $empId)->count() > 0;
            if (!$hasAssignment) {
                throw new AuthorizationException("Akses Ditolak: Anda hanya berwenang melihat aset yang dialokasikan kepada Anda.");
            }
        }

        return $asset;
    }

    public function createAsset(array $data, ?Admin $actor = null): Asset
    {
        return DB::transaction(function () use ($data, $actor) {
            $yearMonth = Carbon::now()->format('Ym');
            $count = Asset::whereYear('created_at', Carbon::now()->year)->count() + 1;
            $assetCode = sprintf('AST-%s-%04d', $yearMonth, $count);

            if (!empty($data['serial_number'])) {
                if (Asset::where('serial_number', $data['serial_number'])->exists()) {
                    throw ValidationException::withMessages([
                        'serial_number' => ['Nomor seri ini sudah terdaftar untuk aset lain.'],
                    ]);
                }
            }

            $buildingName = $data['building_name'] ?? 'Kantor Pusat PKP';
            if ($actor && $actor->isBuildingAdmin() && $actor->assigned_building) {
                $buildingName = $actor->assigned_building;
            }

            $asset = Asset::create([
                'asset_code' => $assetCode,
                'asset_name' => $data['asset_name'],
                'category_id' => $data['category_id'] ?? null,
                'brand' => $data['brand'] ?? null,
                'model' => $data['model'] ?? null,
                'serial_number' => $data['serial_number'] ?? null,
                'purchase_date' => $data['purchase_date'] ?? null,
                'purchase_price' => $data['purchase_price'] ?? 0,
                'vendor' => $data['vendor'] ?? null,
                'warranty_start' => $data['warranty_start'] ?? null,
                'warranty_end' => $data['warranty_end'] ?? null,
                'building_name' => $buildingName,
                'location' => $data['location'] ?? null,
                'division_id' => $data['division_id'] ?? null,
                'status' => 'AVAILABLE',
                'condition' => $data['condition'] ?? 'GOOD',
                'notes' => $data['notes'] ?? null,
            ]);

            ActivityLog::create([
                'admin_id' => $actor?->id,
                'action' => 'asset_created',
                'subject_type' => 'Asset',
                'subject_id' => $asset->id,
                'description' => "Aset {$asset->asset_code} ({$asset->asset_name}) berhasil didaftarkan di {$asset->building_name}.",
                'timestamp' => now(),
            ]);

            return $asset;
        });
    }

    public function updateAsset(Asset $asset, array $data, ?Admin $actor = null): Asset
    {
        if ($actor && $actor->isBuildingAdmin() && $actor->assigned_building) {
            if ($asset->building_name && $asset->building_name !== $actor->assigned_building) {
                throw new AuthorizationException("Akses Ditolak: Anda tidak berwenang memperbarui aset gedung {$asset->building_name}.");
            }
        }

        if (!empty($data['serial_number']) && $data['serial_number'] !== $asset->serial_number) {
            if (Asset::where('serial_number', $data['serial_number'])->where('id', '!=', $asset->id)->exists()) {
                throw ValidationException::withMessages([
                    'serial_number' => ['Nomor seri ini sudah terdaftar untuk aset lain.'],
                ]);
            }
        }

        $asset->update($data);

        ActivityLog::create([
            'admin_id' => $actor?->id,
            'action' => 'asset_updated',
            'subject_type' => 'Asset',
            'subject_id' => $asset->id,
            'description' => "Data aset {$asset->asset_code} diperbarui.",
            'timestamp' => now(),
        ]);

        return $asset;
    }

    // -------------------------------------------------------------
    // ASSET ASSIGNMENTS & HANDOVER
    // -------------------------------------------------------------

    public function getAssignments(array $filters = [], ?Admin $actor = null)
    {
        $query = AssetAssignment::with(['asset.category', 'employee', 'internship', 'assignedByAdmin', 'receivedByAdmin']);

        // Building Admin Scope
        if ($actor && $actor->isBuildingAdmin() && $actor->assigned_building) {
            $query->whereHas('asset', function ($q) use ($actor) {
                $q->where('building_name', $actor->assigned_building);
            });
        }

        // Employee / Intern Scope: only see own assignments
        if ($actor && in_array(strtolower((string)$actor->role), ['employee', 'intern'], true)) {
            $empId = $actor->employee_id ?? $actor->id;
            $query->where('employee_id', $empId);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['employee_id'])) {
            $query->where('employee_id', $filters['employee_id']);
        }
        if (!empty($filters['asset_id'])) {
            $query->where('asset_id', $filters['asset_id']);
        }

        return $query->latest()->get();
    }

    public function assignAsset(Asset $asset, array $data, ?Admin $actor = null): AssetAssignment
    {
        if ($actor && $actor->isBuildingAdmin() && $actor->assigned_building) {
            if ($asset->building_name && $asset->building_name !== $actor->assigned_building) {
                throw new AuthorizationException("Akses Ditolak: Anda tidak berwenang mengalokasikan aset di luar gedung {$actor->assigned_building}.");
            }
        }

        // Rule: Asset must be AVAILABLE
        if ($asset->status !== 'AVAILABLE') {
            throw ValidationException::withMessages([
                'asset_id' => ["Aset {$asset->asset_code} saat ini berstatus {$asset->status} dan tidak dapat dialokasikan."],
            ]);
        }

        // Rule: Assignee check
        $employee = null;
        if (!empty($data['employee_id'])) {
            $employee = Employee::find($data['employee_id']);
            if (!$employee || !in_array(strtoupper((string)$employee->employment_status), ['ACTIVE', 'PROBATION'], true)) {
                throw ValidationException::withMessages([
                    'employee_id' => ['Aset tidak dapat dialokasikan kepada karyawan yang tidak aktif.'],
                ]);
            }
        }

        $intern = null;
        if (!empty($data['internship_id'])) {
            $intern = Internship::find($data['internship_id']);
            if (!$intern || strtoupper((string)$intern->status) !== 'ACTIVE') {
                throw ValidationException::withMessages([
                    'internship_id' => ['Aset tidak dapat dialokasikan kepada pemagang yang programnya telah selesai atau tidak aktif.'],
                ]);
            }
        }

        if (!$employee && !$intern) {
            throw ValidationException::withMessages([
                'employee_id' => ['Wajib memilih penerima aset (karyawan atau pemagang aktif).'],
            ]);
        }

        return DB::transaction(function () use ($asset, $data, $employee, $intern, $actor) {
            $yearMonth = Carbon::now()->format('Ym');
            $count = AssetAssignment::whereYear('created_at', Carbon::now()->year)->count() + 1;
            $asgNumber = sprintf('ASG-%s-%04d', $yearMonth, $count);

            $assignment = AssetAssignment::create([
                'assignment_number' => $asgNumber,
                'asset_id' => $asset->id,
                'employee_id' => $employee?->id,
                'internship_id' => $intern?->id,
                'assigned_by' => $actor?->id,
                'assigned_at' => $data['assigned_at'] ?? now(),
                'expected_return_date' => $data['expected_return_date'] ?? null,
                'condition_out' => $data['condition_out'] ?? $asset->condition,
                'accessories' => $data['accessories'] ?? ['charger' => true, 'bag' => true],
                'handover_notes' => $data['handover_notes'] ?? null,
                'status' => 'ACTIVE',
            ]);

            $asset->status = 'ASSIGNED';
            $asset->save();

            $targetName = $employee ? $employee->name : ($intern ? "Pemagang {$intern->intern_id}" : 'Staff');

            ActivityLog::create([
                'admin_id' => $actor?->id,
                'action' => 'asset_assigned',
                'subject_type' => 'AssetAssignment',
                'subject_id' => $assignment->id,
                'description' => "Aset {$asset->asset_code} ({$asset->asset_name}) diserahkan kepada {$targetName}.",
                'timestamp' => now(),
            ]);

            return $assignment;
        });
    }

    public function returnAsset(AssetAssignment $assignment, array $data, ?Admin $actor = null): AssetAssignment
    {
        if ($assignment->status !== 'ACTIVE') {
            throw ValidationException::withMessages([
                'assignment_id' => ['Penugasan aset ini sudah tidak aktif atau telah dikembalikan sebelumnya.'],
            ]);
        }

        return DB::transaction(function () use ($assignment, $data, $actor) {
            $conditionIn = $data['condition_in'] ?? 'GOOD';
            $assignment->status = 'RETURNED';
            $assignment->actual_return_date = now();
            $assignment->condition_in = $conditionIn;
            $assignment->return_notes = $data['return_notes'] ?? null;
            $assignment->received_by = $actor?->id;
            $assignment->save();

            $asset = $assignment->asset;
            $asset->condition = $conditionIn;

            if (in_array(strtoupper($conditionIn), ['POOR', 'DAMAGED'], true)) {
                $asset->status = 'MAINTENANCE';
            } else {
                $asset->status = 'AVAILABLE';
            }
            $asset->save();

            ActivityLog::create([
                'admin_id' => $actor?->id,
                'action' => 'asset_returned',
                'subject_type' => 'AssetAssignment',
                'subject_id' => $assignment->id,
                'description' => "Aset {$asset->asset_code} telah dikembalikan oleh pemegang dengan kondisi {$conditionIn}.",
                'timestamp' => now(),
            ]);

            return $assignment;
        });
    }

    // -------------------------------------------------------------
    // MAINTENANCE & REPAIRS
    // -------------------------------------------------------------

    public function getMaintenances(array $filters = [], ?Admin $actor = null)
    {
        $query = AssetMaintenance::with(['asset.category', 'performedByAdmin']);

        if ($actor && $actor->isBuildingAdmin() && $actor->assigned_building) {
            $query->whereHas('asset', function ($q) use ($actor) {
                $q->where('building_name', $actor->assigned_building);
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['asset_id'])) {
            $query->where('asset_id', $filters['asset_id']);
        }

        return $query->latest()->get();
    }

    public function openMaintenance(Asset $asset, array $data, ?Admin $actor = null): AssetMaintenance
    {
        return DB::transaction(function () use ($asset, $data, $actor) {
            $yearMonth = Carbon::now()->format('Ym');
            $count = AssetMaintenance::whereYear('created_at', Carbon::now()->year)->count() + 1;
            $mntNumber = sprintf('MNT-%s-%04d', $yearMonth, $count);

            $maintenance = AssetMaintenance::create([
                'maintenance_number' => $mntNumber,
                'asset_id' => $asset->id,
                'maintenance_type' => $data['maintenance_type'] ?? 'PREVENTIVE',
                'issue_description' => $data['issue_description'],
                'vendor' => $data['vendor'] ?? null,
                'cost' => $data['cost'] ?? 0,
                'opened_at' => now(),
                'status' => 'OPEN',
                'notes' => $data['notes'] ?? null,
                'performed_by' => $actor?->id,
            ]);

            $asset->status = 'MAINTENANCE';
            $asset->save();

            ActivityLog::create([
                'admin_id' => $actor?->id,
                'action' => 'asset_maintenance_opened',
                'subject_type' => 'AssetMaintenance',
                'subject_id' => $maintenance->id,
                'description' => "Pemeliharaan/perbaikan {$maintenance->maintenance_number} dibuka untuk aset {$asset->asset_code}.",
                'timestamp' => now(),
            ]);

            return $maintenance;
        });
    }

    public function completeMaintenance(AssetMaintenance $maintenance, array $data, ?Admin $actor = null): AssetMaintenance
    {
        return DB::transaction(function () use ($maintenance, $data, $actor) {
            $maintenance->status = 'COMPLETED';
            $maintenance->completed_at = now();
            $maintenance->result = $data['result'] ?? 'Perbaikan selesai; perangkat berfungsi normal.';
            if (isset($data['cost'])) {
                $maintenance->cost = $data['cost'];
            }
            $maintenance->save();

            $asset = $maintenance->asset;
            $asset->status = 'AVAILABLE';
            $asset->condition = $data['condition'] ?? 'GOOD';
            $asset->save();

            ActivityLog::create([
                'admin_id' => $actor?->id,
                'action' => 'asset_maintenance_completed',
                'subject_type' => 'AssetMaintenance',
                'subject_id' => $maintenance->id,
                'description' => "Pemeliharaan {$maintenance->maintenance_number} aset {$asset->asset_code} selesai. Status aset kembali AVAILABLE.",
                'timestamp' => now(),
            ]);

            return $maintenance;
        });
    }

    // -------------------------------------------------------------
    // INCIDENTS (LOSS, THEFT, DAMAGE)
    // -------------------------------------------------------------

    public function getIncidents(array $filters = [], ?Admin $actor = null)
    {
        $query = AssetIncident::with(['asset.category', 'employee', 'reportedByAdmin']);

        if ($actor && $actor->isBuildingAdmin() && $actor->assigned_building) {
            $query->whereHas('asset', function ($q) use ($actor) {
                $q->where('building_name', $actor->assigned_building);
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['incident_type'])) {
            $query->where('incident_type', $filters['incident_type']);
        }

        return $query->latest()->get();
    }

    public function reportIncident(Asset $asset, array $data, ?Admin $actor = null): AssetIncident
    {
        return DB::transaction(function () use ($asset, $data, $actor) {
            $yearMonth = Carbon::now()->format('Ym');
            $count = AssetIncident::whereYear('created_at', Carbon::now()->year)->count() + 1;
            $incNumber = sprintf('INC-%s-%04d', $yearMonth, $count);

            $incident = AssetIncident::create([
                'incident_number' => $incNumber,
                'asset_id' => $asset->id,
                'reported_by' => $actor?->id,
                'employee_id' => $data['employee_id'] ?? null,
                'incident_type' => $data['incident_type'] ?? 'DAMAGED',
                'incident_date' => $data['incident_date'] ?? now(),
                'location' => $data['location'] ?? $asset->location,
                'description' => $data['description'],
                'status' => 'REPORTED',
            ]);

            if ($incident->incident_type === 'LOST' || $incident->incident_type === 'STOLEN') {
                $asset->status = 'LOST';
            } elseif ($incident->incident_type === 'DAMAGED') {
                $asset->status = 'DAMAGED';
                $asset->condition = 'DAMAGED';
            }
            $asset->save();

            ActivityLog::create([
                'admin_id' => $actor?->id,
                'action' => 'asset_incident_reported',
                'subject_type' => 'AssetIncident',
                'subject_id' => $incident->id,
                'description' => "Insiden aset {$incident->incident_number} ({$incident->incident_type}) dilaporkan untuk aset {$asset->asset_code}.",
                'timestamp' => now(),
            ]);

            return $incident;
        });
    }

    public function resolveIncident(AssetIncident $incident, array $data, ?Admin $actor = null): AssetIncident
    {
        return DB::transaction(function () use ($incident, $data, $actor) {
            $incident->status = 'RESOLVED';
            $incident->resolved_at = now();
            $incident->resolution = $data['resolution'] ?? 'Insiden telah ditangani.';
            $incident->save();

            ActivityLog::create([
                'admin_id' => $actor?->id,
                'action' => 'asset_incident_resolved',
                'subject_type' => 'AssetIncident',
                'subject_id' => $incident->id,
                'description' => "Insiden aset {$incident->incident_number} telah diselesaikan.",
                'timestamp' => now(),
            ]);

            return $incident;
        });
    }

    // -------------------------------------------------------------
    // RETIREMENT & DISPOSAL
    // -------------------------------------------------------------

    public function disposeAsset(Asset $asset, array $data, ?Admin $actor = null): Asset
    {
        if ($asset->status === 'ASSIGNED') {
            throw ValidationException::withMessages([
                'asset_id' => ['Aset tidak dapat dihapusbukukan karena masih dalam status dialokasikan ke karyawan.'],
            ]);
        }

        return DB::transaction(function () use ($asset, $data, $actor) {
            $asset->status = 'DISPOSED';
            $asset->disposed_at = now();
            $asset->disposal_reason = $data['disposal_reason'] ?? 'Aset habis masa manfaat / rusak permanen.';
            $asset->disposal_method = $data['disposal_method'] ?? 'SCRAP';
            $asset->save();

            ActivityLog::create([
                'admin_id' => $actor?->id,
                'action' => 'asset_disposed',
                'subject_type' => 'Asset',
                'subject_id' => $asset->id,
                'description' => "Aset {$asset->asset_code} dihapusbukukan (DISPOSED). Alasan: {$asset->disposal_reason}",
                'timestamp' => now(),
            ]);

            return $asset;
        });
    }

    // -------------------------------------------------------------
    // ONBOARDING & INTERNSHIP INTEGRATION
    // -------------------------------------------------------------

    public function assignOnboardingAsset(OnboardingCase $case, Asset $asset, ?Admin $actor = null): AssetAssignment
    {
        $assignment = $this->assignAsset($asset, [
            'employee_id' => $case->employee_id,
            'internship_id' => $case->internship_id,
            'handover_notes' => "Penyerahan aset onboarding kasus {$case->case_number}",
        ], $actor);

        $task = OnboardingTask::where('onboarding_case_id', $case->id)
            ->where('task_key', 'asset_assignment')
            ->first();
        if ($task && $task->status !== 'COMPLETED') {
            $task->update([
                'status' => 'COMPLETED',
                'completed_at' => now(),
                'completed_by' => $actor?->id,
                'notes' => "Aset {$asset->asset_code} ({$asset->asset_name}) diserahkan (ASG: {$assignment->assignment_number})",
            ]);
        }

        return $assignment;
    }

    public function checkOutstandingAssetsForIntern(Internship $internship): int
    {
        return AssetAssignment::where('internship_id', $internship->id)
            ->where('status', 'ACTIVE')
            ->count();
    }

    // -------------------------------------------------------------
    // METRICS & DASHBOARD
    // -------------------------------------------------------------

    public function getMetrics(?Admin $actor = null): array
    {
        $query = Asset::query();
        if ($actor && $actor->isBuildingAdmin() && $actor->assigned_building) {
            $query->where('building_name', $actor->assigned_building);
        }

        $now = Carbon::now();
        $thirtyDaysAhead = Carbon::now()->addDays(30);

        return [
            'total_assets' => (clone $query)->whereNotIn('status', ['DISPOSED'])->count(),
            'available_assets' => (clone $query)->where('status', 'AVAILABLE')->count(),
            'assigned_assets' => (clone $query)->where('status', 'ASSIGNED')->count(),
            'maintenance_assets' => (clone $query)->whereIn('status', ['MAINTENANCE', 'REPAIR'])->count(),
            'lost_or_damaged_assets' => (clone $query)->whereIn('status', ['LOST', 'DAMAGED'])->count(),
            'expiring_warranties' => (clone $query)->whereNotNull('warranty_end')->whereBetween('warranty_end', [$now, $thirtyDaysAhead])->count(),
            'total_disposed' => (clone $query)->where('status', 'DISPOSED')->count(),
        ];
    }
}
