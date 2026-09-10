<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\FieldAssignment;
use App\Models\FieldAttendanceEvidence;
use App\Models\FieldLocation;
use App\Policies\FieldAttendancePolicy;
use App\Services\FieldAttendanceService;
use App\Services\PortalAccess;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FieldAttendanceController extends Controller
{
    public function __construct(
        protected FieldAttendanceService $fieldService,
        protected FieldAttendancePolicy $policy,
        protected PortalAccess $portalAccess
    ) {}

    // =========================================================================
    // FIELD LOCATIONS
    // =========================================================================

    public function listLocations(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$this->policy->viewAny($user)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $query = FieldLocation::query();

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('project_name', 'like', "%{$s}%")
                  ->orWhere('client_name', 'like', "%{$s}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        return response()->json($query->orderBy('name')->paginate(20));
    }

    public function storeLocation(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$this->policy->manageLocations($user)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'project_name' => 'nullable|string|max:255',
            'client_name' => 'nullable|string|max:255',
            'site_address' => 'nullable|string|max:500',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'radius_meters' => 'required|integer|min:10|max:5000',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date|after_or_equal:valid_from',
            'is_active' => 'nullable|boolean',
        ]);

        $validated['created_by'] = $user->id;
        $location = FieldLocation::create($validated);

        ActivityLog::create([
            'admin_id' => $user->id,
            'action' => 'CREATE_FIELD_LOCATION',
            'subject_type' => 'FieldLocation',
            'subject_id' => $location->id,
            'description' => "Membuat lokasi presensi lapangan: {$location->name} (Radius: {$location->radius_meters}m)",
            'timestamp' => now(),
        ]);

        return response()->json($location, 201);
    }

    public function showLocation(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$this->policy->viewAny($user)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $location = FieldLocation::findOrFail($id);
        return response()->json($location);
    }

    public function updateLocation(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$this->policy->manageLocations($user)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $location = FieldLocation::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'project_name' => 'nullable|string|max:255',
            'client_name' => 'nullable|string|max:255',
            'site_address' => 'nullable|string|max:500',
            'latitude' => 'sometimes|required|numeric|between:-90,90',
            'longitude' => 'sometimes|required|numeric|between:-180,180',
            'radius_meters' => 'sometimes|required|integer|min:10|max:5000',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date|after_or_equal:valid_from',
            'is_active' => 'nullable|boolean',
        ]);

        $location->update($validated);

        ActivityLog::create([
            'admin_id' => $user->id,
            'action' => 'UPDATE_FIELD_LOCATION',
            'subject_type' => 'FieldLocation',
            'subject_id' => $location->id,
            'description' => "Memperbarui lokasi presensi lapangan: {$location->name}",
            'timestamp' => now(),
        ]);

        return response()->json($location);
    }

    public function destroyLocation(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$this->policy->manageLocations($user)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $location = FieldLocation::findOrFail($id);
        $location->delete();

        ActivityLog::create([
            'admin_id' => $user->id,
            'action' => 'DELETE_FIELD_LOCATION',
            'subject_type' => 'FieldLocation',
            'subject_id' => $location->id,
            'description' => "Menonaktifkan lokasi presensi lapangan: {$location->name}",
            'timestamp' => now(),
        ]);

        return response()->json(['message' => 'Lokasi presensi lapangan berhasil dihapus.']);
    }

    // =========================================================================
    // FIELD ASSIGNMENTS
    // =========================================================================

    public function listAssignments(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$this->policy->viewAny($user)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $role = strtolower((string) $user->role);
        $query = FieldAssignment::with(['employee', 'fieldLocation', 'supervisor']);

        if (in_array($role, ['employee', 'intern'], true)) {
            $query->where('employee_id', $user->employee_id);
        } elseif ($role === 'supervisor') {
            $query->where(function ($q) use ($user) {
                $q->where('supervisor_id', $user->employee_id)
                  ->orWhereHas('employee', function ($eq) use ($user) {
                      $eq->where('supervisor_id', $user->employee_id);
                  });
            });
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->orderByDesc('start_date')->paginate(20));
    }

    public function storeAssignment(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$this->policy->manage($user)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'field_location_id' => 'required|exists:field_locations,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'supervisor_id' => 'nullable|exists:employees,id',
            'status' => 'nullable|in:ACTIVE,PENDING,COMPLETED,REVOKED',
            'notes' => 'nullable|string|max:500',
        ]);

        $validated['approved_by'] = $user->id;
        $validated['approved_at'] = now();
        $validated['status'] = $validated['status'] ?? 'ACTIVE';

        $assignment = FieldAssignment::create($validated);

        ActivityLog::create([
            'admin_id' => $user->id,
            'action' => 'CREATE_FIELD_ASSIGNMENT',
            'subject_type' => 'FieldAssignment',
            'subject_id' => $assignment->id,
            'description' => "Membuat penugasan lapangan untuk karyawan ID {$assignment->employee_id} di lokasi ID {$assignment->field_location_id} ({$assignment->start_date->toDateString()} s/d {$assignment->end_date->toDateString()})",
            'timestamp' => now(),
        ]);

        return response()->json($assignment->load(['employee', 'fieldLocation', 'supervisor']), 201);
    }

    public function myAssignment(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user->employee_id) {
            return response()->json(['message' => 'Pengguna tidak memiliki profil karyawan.'], 404);
        }

        $employee = Employee::find($user->employee_id);
        if (!$employee) {
            return response()->json(['message' => 'Karyawan tidak ditemukan.'], 404);
        }

        $assignment = $this->fieldService->resolveActiveAssignment($employee, now());

        if (!$assignment) {
            return response()->json([
                'assignment' => null,
                'message' => 'Tidak ada penugasan lapangan aktif untuk hari ini.',
            ]);
        }

        return response()->json([
            'assignment' => $assignment->load('fieldLocation'),
        ]);
    }

    // =========================================================================
    // CHECK-IN / CHECK-OUT
    // =========================================================================

    public function checkIn(Request $request): JsonResponse
    {
        $user = $request->user();

        $employeeId = $request->input('employee_id', $user->employee_id);
        if (!$employeeId) {
            return response()->json(['message' => 'Profil karyawan tidak ditemukan.'], 422);
        }

        $employee = Employee::findOrFail($employeeId);

        if (!$this->policy->submit($user, $employee)) {
            return response()->json(['message' => 'Akses ditolak. Anda tidak berhak melakukan presensi untuk karyawan ini.'], 403);
        }

        $validated = $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'accuracy_meters' => 'required|numeric|min:0.01|max:1000',
            'captured_at' => 'nullable|date',
            'photo' => 'required|file|mimes:jpg,jpeg,png,webp|max:5120',
            'notes' => 'nullable|string|max:500',
        ]);

        $gpsData = [
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'accuracy_meters' => $validated['accuracy_meters'],
            'captured_at' => $validated['captured_at'] ?? null,
        ];

        $evidence = $this->fieldService->recordCheckIn(
            $user,
            $employee,
            $gpsData,
            $request->file('photo'),
            $validated['notes'] ?? null
        );

        $statusMessage = match ($evidence->geofence_result) {
            'VALID' => 'Check-in lapangan berhasil dan terverifikasi.',
            'OUTSIDE_GEOFENCE' => 'Bukti check-in tersimpan. Anda berada di luar radius lokasi dan memerlukan verifikasi HRD.',
            'LOW_ACCURACY' => 'Bukti check-in tersimpan. Akurasi GPS rendah dan memerlukan verifikasi HRD.',
            default => 'Bukti check-in tersimpan dengan catatan anomali.',
        };

        return response()->json([
            'message' => $statusMessage,
            'evidence' => $evidence,
        ], 201);
    }

    public function checkOut(Request $request): JsonResponse
    {
        $user = $request->user();

        $employeeId = $request->input('employee_id', $user->employee_id);
        if (!$employeeId) {
            return response()->json(['message' => 'Profil karyawan tidak ditemukan.'], 422);
        }

        $employee = Employee::findOrFail($employeeId);

        if (!$this->policy->submit($user, $employee)) {
            return response()->json(['message' => 'Akses ditolak. Anda tidak berhak melakukan presensi untuk karyawan ini.'], 403);
        }

        $validated = $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'accuracy_meters' => 'required|numeric|min:0.01|max:1000',
            'captured_at' => 'nullable|date',
            'photo' => 'required|file|mimes:jpg,jpeg,png,webp|max:5120',
            'notes' => 'nullable|string|max:500',
        ]);

        $gpsData = [
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'accuracy_meters' => $validated['accuracy_meters'],
            'captured_at' => $validated['captured_at'] ?? null,
        ];

        $evidence = $this->fieldService->recordCheckOut(
            $user,
            $employee,
            $gpsData,
            $request->file('photo'),
            $validated['notes'] ?? null
        );

        $statusMessage = match ($evidence->geofence_result) {
            'VALID' => 'Check-out lapangan berhasil dan terverifikasi.',
            'OUTSIDE_GEOFENCE' => 'Bukti check-out tersimpan. Anda berada di luar radius lokasi dan memerlukan verifikasi HRD.',
            'LOW_ACCURACY' => 'Bukti check-out tersimpan. Akurasi GPS rendah dan memerlukan verifikasi HRD.',
            default => 'Bukti check-out tersimpan dengan catatan anomali.',
        };

        return response()->json([
            'message' => $statusMessage,
            'evidence' => $evidence,
        ], 201);
    }

    // =========================================================================
    // STATUS & RECORDS
    // =========================================================================

    public function statusToday(Request $request): JsonResponse
    {
        $user = $request->user();
        $employeeId = $request->input('employee_id', $user->employee_id);

        if (!$employeeId) {
            return response()->json(['message' => 'Profil karyawan tidak ditemukan.'], 404);
        }

        $employee = Employee::find($employeeId);
        if (!$employee) {
            return response()->json(['message' => 'Karyawan tidak ditemukan.'], 404);
        }

        $today = now()->toDateString();
        $assignment = $this->fieldService->resolveActiveAssignment($employee, now());

        $attendance = \App\Models\Attendance::where('employee_id', $employee->id)
            ->whereDate('attendance_date', $today)
            ->first();

        $evidences = FieldAttendanceEvidence::with('fieldLocation')
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', $today)
            ->orderBy('captured_at')
            ->get();

        return response()->json([
            'date' => $today,
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
            ],
            'assignment' => $assignment?->load('fieldLocation'),
            'attendance' => $attendance,
            'evidences' => $evidences,
        ]);
    }

    public function listRecords(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$this->policy->viewAny($user)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $role = strtolower((string) $user->role);
        $query = FieldAttendanceEvidence::with(['employee', 'fieldLocation', 'fieldAssignment']);

        if (in_array($role, ['employee', 'intern'], true)) {
            $query->where('employee_id', $user->employee_id);
        } elseif ($role === 'supervisor') {
            $query->where(function ($q) use ($user) {
                $q->whereHas('employee', function ($eq) use ($user) {
                    $eq->where('supervisor_id', $user->employee_id);
                })->orWhereHas('fieldAssignment', function ($aq) use ($user) {
                    $aq->where('supervisor_id', $user->employee_id);
                });
            });
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }
        if ($request->filled('location_id')) {
            $query->where('field_location_id', $request->location_id);
        }
        if ($request->filled('date')) {
            $query->whereDate('attendance_date', $request->date);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('geofence_result')) {
            $query->where('geofence_result', $request->geofence_result);
        }

        return response()->json($query->orderByDesc('captured_at')->paginate(20));
    }

    public function showRecord(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $evidence = FieldAttendanceEvidence::with(['employee', 'fieldLocation', 'fieldAssignment', 'attendance', 'overrideAdmin'])->findOrFail($id);

        if (!$this->policy->view($user, $evidence)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return response()->json($evidence);
    }

    // =========================================================================
    // MANUAL OVERRIDE
    // =========================================================================

    public function overrideRecord(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$this->policy->override($user)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $evidence = FieldAttendanceEvidence::findOrFail($id);

        $request->validate([
            'reason' => 'required|string|min:5|max:500',
        ]);

        $updated = $this->fieldService->manualOverride($user, $evidence, $request->reason);

        return response()->json([
            'message' => 'Manual override presensi lapangan berhasil diterapkan.',
            'evidence' => $updated,
        ]);
    }

    // =========================================================================
    // SECURE PHOTO STREAM / DOWNLOAD
    // =========================================================================

    public function downloadPhoto(Request $request, int $id): BinaryFileResponse|JsonResponse
    {
        $user = $request->user();
        $evidence = FieldAttendanceEvidence::with(['employee', 'fieldAssignment'])->findOrFail($id);

        if (!$this->policy->viewPhoto($user, $evidence)) {
            return response()->json(['message' => 'Akses foto bukti presensi lapangan ditolak.'], 403);
        }

        $diskName = config('field_attendance.photo_disk', 'local');
        $disk = Storage::disk($diskName);

        if (!$disk->exists($evidence->photo_path)) {
            return response()->json(['message' => 'Berkas foto tidak ditemukan di server.'], 404);
        }

        $absolutePath = $disk->path($evidence->photo_path);

        // Security check: ensure path is within storage directory to prevent path traversal
        $diskRoot = realpath($disk->path('')) ?: $disk->path('');
        $realFile = realpath($absolutePath) ?: $absolutePath;
        $normDiskRoot = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $diskRoot), DIRECTORY_SEPARATOR);
        $normRealFile = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $realFile);

        if (!str_starts_with($normRealFile, $normDiskRoot)) {
            return response()->json(['message' => 'Invalid file path traversal detected.'], 400);
        }

        // Audit photo access
        ActivityLog::create([
            'admin_id' => $user->id,
            'action' => 'VIEW_FIELD_PHOTO',
            'subject_type' => 'FieldAttendanceEvidence',
            'subject_id' => $evidence->id,
            'description' => "Akses foto bukti presensi lapangan ID {$evidence->id} oleh {$user->name} ({$user->role})",
            'timestamp' => now(),
        ]);

        return response()->file($absolutePath, [
            'Content-Type' => $evidence->photo_mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
