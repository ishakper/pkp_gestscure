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
use OpenApi\Attributes as OA;
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

    #[OA\Get(
        path: '/api/v1/field-attendance/locations',
        summary: 'Daftar Lokasi Presensi Lapangan',
        description: 'Mengambil daftar lokasi presensi lapangan / geofence dengan pencarian dan filter status aktif.',
        security: [['sanctum' => []]],
        tags: ['Field Attendance'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', description: 'Kata kunci nama lokasi / proyek / klien', required: false, schema: new OA\Schema(type: 'string', example: 'Proyek Lapangan A')),
            new OA\Parameter(name: 'is_active', in: 'query', description: 'Filter status aktif (true/false)', required: false, schema: new OA\Schema(type: 'boolean', example: true)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar lokasi presensi lapangan berhasil didapatkan'),
            new OA\Response(response: 403, description: 'Akses ditolak')
        ]
    )]
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

    #[OA\Post(
        path: '/api/v1/field-attendance/locations',
        summary: 'Tambah Lokasi Presensi Lapangan Baru',
        description: 'Membuat lokasi presensi lapangan baru beserta koordinat geofence (latitude, longitude, radius).',
        security: [['sanctum' => []]],
        tags: ['Field Attendance'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'latitude', 'longitude', 'radius_meters'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Site Proyek Konstruksi B'),
                    new OA\Property(property: 'project_name', type: 'string', example: 'Pembangunan Gedung C'),
                    new OA\Property(property: 'client_name', type: 'string', example: 'PT Mitra Utama'),
                    new OA\Property(property: 'site_address', type: 'string', example: 'Jl. Jendral Sudirman No. 123, Jakarta'),
                    new OA\Property(property: 'latitude', type: 'number', format: 'float', example: -6.2088),
                    new OA\Property(property: 'longitude', type: 'number', format: 'float', example: 106.8456),
                    new OA\Property(property: 'radius_meters', type: 'integer', example: 150),
                    new OA\Property(property: 'valid_from', type: 'string', format: 'date', example: '2026-01-01'),
                    new OA\Property(property: 'valid_until', type: 'string', format: 'date', example: '2026-12-31'),
                    new OA\Property(property: 'is_active', type: 'boolean', example: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Lokasi presensi lapangan berhasil dibuat'),
            new OA\Response(response: 403, description: 'Akses ditolak / tidak memiliki kewenangan'),
            new OA\Response(response: 422, description: 'Validasi gagal')
        ]
    )]
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

    #[OA\Get(
        path: '/api/v1/field-attendance/locations/{id}',
        summary: 'Detail Lokasi Presensi Lapangan',
        description: 'Mengambil detail lengkap lokasi presensi lapangan berdasarkan ID.',
        security: [['sanctum' => []]],
        tags: ['Field Attendance'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Lokasi', required: true, schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detail lokasi berhasil ditemukan'),
            new OA\Response(response: 404, description: 'Lokasi tidak ditemukan')
        ]
    )]
    public function showLocation(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$this->policy->viewAny($user)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $location = FieldLocation::findOrFail($id);
        return response()->json($location);
    }

    #[OA\Put(
        path: '/api/v1/field-attendance/locations/{id}',
        summary: 'Perbarui Lokasi Presensi Lapangan',
        description: 'Memperbarui data atau parameter geofence lokasi presensi lapangan.',
        security: [['sanctum' => []]],
        tags: ['Field Attendance'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Lokasi', required: true, schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Site Proyek Karyawan Update'),
                    new OA\Property(property: 'radius_meters', type: 'integer', example: 200),
                    new OA\Property(property: 'is_active', type: 'boolean', example: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Lokasi presensi berhasil diperbarui'),
            new OA\Response(response: 403, description: 'Akses ditolak'),
            new OA\Response(response: 404, description: 'Lokasi tidak ditemukan')
        ]
    )]
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

    #[OA\Delete(
        path: '/api/v1/field-attendance/locations/{id}',
        summary: 'Hapus Lokasi Presensi Lapangan',
        description: 'Menghapus lokasi presensi lapangan dari sistem.',
        security: [['sanctum' => []]],
        tags: ['Field Attendance'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Lokasi', required: true, schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lokasi berhasil dihapus'),
            new OA\Response(response: 403, description: 'Akses ditolak'),
            new OA\Response(response: 404, description: 'Lokasi tidak ditemukan')
        ]
    )]
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

    #[OA\Get(
        path: '/api/v1/field-attendance/assignments',
        summary: 'Daftar Penugasan Lapangan',
        description: 'Mengambil daftar penugasan lokasi lapangan untuk karyawan.',
        security: [['sanctum' => []]],
        tags: ['Field Attendance'],
        parameters: [
            new OA\Parameter(name: 'employee_id', in: 'query', description: 'Filter ID Karyawan', required: false, schema: new OA\Schema(type: 'integer', example: 10)),
            new OA\Parameter(name: 'status', in: 'query', description: 'Filter status (ACTIVE, PENDING, COMPLETED, REVOKED)', required: false, schema: new OA\Schema(type: 'string', example: 'ACTIVE')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar penugasan lapangan berhasil didapatkan'),
            new OA\Response(response: 403, description: 'Akses ditolak')
        ]
    )]
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

    #[OA\Post(
        path: '/api/v1/field-attendance/assignments',
        summary: 'Buat Penugasan Lapangan Baru',
        description: 'Menugaskan karyawan ke lokasi lapangan tertentu pada periode tanggal tertentu.',
        security: [['sanctum' => []]],
        tags: ['Field Attendance'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['employee_id', 'field_location_id', 'start_date', 'end_date'],
                properties: [
                    new OA\Property(property: 'employee_id', type: 'integer', example: 10),
                    new OA\Property(property: 'field_location_id', type: 'integer', example: 1),
                    new OA\Property(property: 'start_date', type: 'string', format: 'date', example: '2026-03-01'),
                    new OA\Property(property: 'end_date', type: 'string', format: 'date', example: '2026-03-31'),
                    new OA\Property(property: 'supervisor_id', type: 'integer', example: 2),
                    new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'PENDING', 'COMPLETED', 'REVOKED'], example: 'ACTIVE'),
                    new OA\Property(property: 'notes', type: 'string', example: 'Penugasan audit lapangan site Surabaya'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Penugasan lapangan berhasil dibuat'),
            new OA\Response(response: 403, description: 'Akses ditolak'),
            new OA\Response(response: 422, description: 'Validasi gagal')
        ]
    )]
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

    #[OA\Get(
        path: '/api/v1/field-attendance/assignments/my',
        summary: 'Penugasan Lapangan Saya Hari Ini',
        description: 'Mengambil penugasan lapangan aktif untuk pengguna yang sedang login pada hari ini.',
        security: [['sanctum' => []]],
        tags: ['Field Attendance'],
        responses: [
            new OA\Response(response: 200, description: 'Penugasan aktif berhasil didapatkan'),
            new OA\Response(response: 404, description: 'Profil karyawan tidak ditemukan')
        ]
    )]
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

    #[OA\Post(
        path: '/api/v1/field-attendance/check-in',
        summary: 'Check-in Presensi Lapangan',
        description: 'Melakukan check-in presensi lapangan dengan menyertakan bukti foto swafoto dan lokasi GPS.',
        security: [['sanctum' => []]],
        tags: ['Field Attendance'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['latitude', 'longitude', 'accuracy_meters', 'photo'],
                    properties: [
                        new OA\Property(property: 'latitude', type: 'number', format: 'float', example: -6.2088),
                        new OA\Property(property: 'longitude', type: 'number', format: 'float', example: 106.8456),
                        new OA\Property(property: 'accuracy_meters', type: 'number', format: 'float', example: 5.0),
                        new OA\Property(property: 'captured_at', type: 'string', format: 'date-time', example: '2026-03-17T08:00:00Z'),
                        new OA\Property(property: 'photo', type: 'string', format: 'binary', description: 'Berkas foto swafoto presensi (JPG/PNG/WEBP)'),
                        new OA\Property(property: 'notes', type: 'string', example: 'Tiba di lokasi site proyek'),
                        new OA\Property(property: 'employee_id', type: 'integer', description: 'ID Karyawan (opsional, default ID sendiri)', example: 10),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Check-in lapangan berhasil dicatat'),
            new OA\Response(response: 403, description: 'Akses ditolak'),
            new OA\Response(response: 422, description: 'Validasi gagal / lokasi diluar geofence / foto tidak valid')
        ]
    )]
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

    #[OA\Post(
        path: '/api/v1/field-attendance/check-out',
        summary: 'Check-out Presensi Lapangan',
        description: 'Melakukan check-out presensi lapangan dengan menyertakan bukti foto swafoto dan lokasi GPS.',
        security: [['sanctum' => []]],
        tags: ['Field Attendance'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['latitude', 'longitude', 'accuracy_meters', 'photo'],
                    properties: [
                        new OA\Property(property: 'latitude', type: 'number', format: 'float', example: -6.2088),
                        new OA\Property(property: 'longitude', type: 'number', format: 'float', example: 106.8456),
                        new OA\Property(property: 'accuracy_meters', type: 'number', format: 'float', example: 5.0),
                        new OA\Property(property: 'captured_at', type: 'string', format: 'date-time', example: '2026-03-17T17:00:00Z'),
                        new OA\Property(property: 'photo', type: 'string', format: 'binary', description: 'Berkas foto swafoto presensi (JPG/PNG/WEBP)'),
                        new OA\Property(property: 'notes', type: 'string', example: 'Pekerjaan site selesai'),
                        new OA\Property(property: 'employee_id', type: 'integer', description: 'ID Karyawan (opsional, default ID sendiri)', example: 10),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Check-out lapangan berhasil dicatat'),
            new OA\Response(response: 403, description: 'Akses ditolak'),
            new OA\Response(response: 422, description: 'Validasi gagal / foto tidak valid')
        ]
    )]
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

    #[OA\Get(
        path: '/api/v1/field-attendance/status-today',
        summary: 'Status Presensi Lapangan Hari Ini',
        description: 'Mengambil ringkasan penugasan dan riwayat bukti presensi lapangan karyawan pada hari ini.',
        security: [['sanctum' => []]],
        tags: ['Field Attendance'],
        parameters: [
            new OA\Parameter(name: 'employee_id', in: 'query', description: 'Filter ID Karyawan', required: false, schema: new OA\Schema(type: 'integer', example: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Status presensi hari ini berhasil didapatkan'),
            new OA\Response(response: 404, description: 'Karyawan tidak ditemukan')
        ]
    )]
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

    #[OA\Get(
        path: '/api/v1/field-attendance/records',
        summary: 'Daftar Riwayat Bukti Presensi Lapangan',
        description: 'Mengambil riwayat bukti check-in/check-out presensi lapangan beserta status verifikasi geofence.',
        security: [['sanctum' => []]],
        tags: ['Field Attendance'],
        parameters: [
            new OA\Parameter(name: 'employee_id', in: 'query', description: 'Filter ID Karyawan', required: false, schema: new OA\Schema(type: 'integer', example: 10)),
            new OA\Parameter(name: 'location_id', in: 'query', description: 'Filter ID Lokasi Lapangan', required: false, schema: new OA\Schema(type: 'integer', example: 1)),
            new OA\Parameter(name: 'date', in: 'query', description: 'Filter Tanggal (YYYY-MM-DD)', required: false, schema: new OA\Schema(type: 'string', format: 'date', example: '2026-03-17')),
            new OA\Parameter(name: 'type', in: 'query', description: 'Tipe presensi (CHECK_IN / CHECK_OUT)', required: false, schema: new OA\Schema(type: 'string', example: 'CHECK_IN')),
            new OA\Parameter(name: 'geofence_result', in: 'query', description: 'Hasil geofence (VALID, OUTSIDE_GEOFENCE, LOW_ACCURACY)', required: false, schema: new OA\Schema(type: 'string', example: 'VALID')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Riwayat bukti presensi berhasil didapatkan'),
            new OA\Response(response: 403, description: 'Akses ditolak')
        ]
    )]
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

    #[OA\Get(
        path: '/api/v1/field-attendance/records/{id}',
        summary: 'Detail Bukti Presensi Lapangan',
        description: 'Mengambil detail riwayat bukti presensi lapangan berdasarkan ID record.',
        security: [['sanctum' => []]],
        tags: ['Field Attendance'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Record Evidence', required: true, schema: new OA\Schema(type: 'integer', example: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detail bukti presensi berhasil ditemukan'),
            new OA\Response(response: 403, description: 'Akses ditolak'),
            new OA\Response(response: 404, description: 'Record tidak ditemukan')
        ]
    )]
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

    #[OA\Post(
        path: '/api/v1/field-attendance/records/{id}/override',
        summary: 'Override Manual Bukti Presensi Lapangan',
        description: 'Melakukan override status geofence bukti presensi secara manual oleh HRD/Admin.',
        security: [['sanctum' => []]],
        tags: ['Field Attendance'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Record Evidence', required: true, schema: new OA\Schema(type: 'integer', example: 100)),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason'],
                properties: [
                    new OA\Property(property: 'reason', type: 'string', example: 'Verifikasi manual HRD: sinyal GPS terhalang struktur bangunan site'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Override manual presensi berhasil diterapkan'),
            new OA\Response(response: 403, description: 'Akses ditolak / tidak berhak override'),
            new OA\Response(response: 422, description: 'Alasan override wajib diisi minimal 5 karakter')
        ]
    )]
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

    #[OA\Get(
        path: '/api/v1/field-attendance/records/{id}/photo',
        summary: 'Unduh / Stream Foto Swafoto Bukti Presensi Lapangan',
        description: 'Mendapatkan stream foto swafoto bukti presensi secara aman dengan kontrol otorisasi.',
        security: [['sanctum' => []]],
        tags: ['Field Attendance'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Record Evidence', required: true, schema: new OA\Schema(type: 'integer', example: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Foto bukti presensi dikembalikan (Binary stream image)'),
            new OA\Response(response: 403, description: 'Akses foto ditolak'),
            new OA\Response(response: 404, description: 'Berkas foto tidak ditemukan di server')
        ]
    )]
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
