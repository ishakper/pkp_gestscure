<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeCalendarAssignment;
use App\Models\PublicHoliday;
use App\Models\WorkCalendar;
use App\Models\WorkScheduleDay;
use App\Policies\AttendancePolicy;
use App\Services\AttendanceProcessor;
use App\Services\PortalAccess;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class AttendanceController extends Controller
{
    protected AttendanceProcessor $processor;
    protected AttendancePolicy    $policy;
    protected PortalAccess        $portalAccess;

    public function __construct(
        AttendanceProcessor $processor,
        AttendancePolicy    $policy,
        PortalAccess        $portalAccess
    ) {
        $this->processor    = $processor;
        $this->policy       = $policy;
        $this->portalAccess = $portalAccess;
    }

    #[OA\Get(
        path: '/attendance/metrics',
        summary: 'Metrik Rekap Presensi Karyawan',
        description: 'Mendapatkan statistik ringkas kehadiran, keterlambatan, dan jam kerja.',
        tags: ['Attendance'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Metrik presensi berhasil diambil')
        ]
    )]
    public function metrics(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->viewAny($actor)) {
            return response()->json(['message' => 'Unauthorized to view attendance metrics.'], 403);
        }
        return response()->json(['success' => true, 'data' => $this->processor->getMetrics($actor)]);
    }

    #[OA\Get(
        path: '/attendance/calendars',
        summary: 'Daftar Kalender Kerja',
        description: 'Mendapatkan daftar kalender jadwal kerja operasional.',
        tags: ['Attendance'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Daftar kalender kerja berhasil diambil')
        ]
    )]
    public function calendars(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->viewAny($actor)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $query = WorkCalendar::with('scheduleDays')->where('is_active', true);

        return response()->json(['success' => true, 'data' => $query->get()]);
    }

    #[OA\Post(
        path: '/attendance/calendars',
        summary: 'Buat Kalender Kerja Baru',
        description: 'Mendaftarkan kalender jadwal jam kerja operasional perusahaan.',
        tags: ['Attendance'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'code'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Jam Kerja Reguler Office'),
                    new OA\Property(property: 'code', type: 'string', example: 'CAL-REGULAR'),
                    new OA\Property(property: 'late_tolerance_minutes', type: 'integer', example: 15)
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Kalender kerja berhasil dibuat')
        ]
    )]
    public function storeCalendar(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->manageCalendar($actor)) {
            return response()->json(['message' => 'Unauthorized to manage calendars.'], 403);
        }

        $data = $request->validate([
            'name'                    => 'required|string|max:120',
            'code'                    => 'required|string|max:40|unique:work_calendars,code',
            'description'             => 'nullable|string',
            'building_id'             => 'nullable|integer|exists:buildings,id',
            'late_tolerance_minutes'  => 'nullable|integer|min:0|max:120',
            'is_default'              => 'nullable|boolean',
            'days'                    => 'nullable|array',
            'days.*.day_of_week'      => 'required_with:days|integer|min:0|max:6',
            'days.*.is_working_day'   => 'nullable|boolean',
            'days.*.work_start'       => 'nullable|date_format:H:i',
            'days.*.work_end'         => 'nullable|date_format:H:i',
            'days.*.check_in_start'   => 'nullable|date_format:H:i',
            'days.*.check_in_end'     => 'nullable|date_format:H:i',
            'days.*.check_out_start'  => 'nullable|date_format:H:i',
        ]);

        $calendar = WorkCalendar::create([
            'name'                   => $data['name'],
            'code'                   => strtoupper($data['code']),
            'description'            => $data['description'] ?? null,
            'building_id'            => $data['building_id'] ?? null,
            'late_tolerance_minutes' => $data['late_tolerance_minutes'] ?? 30,
            'is_default'             => $data['is_default'] ?? false,
            'is_active'              => true,
        ]);

        if (!empty($data['days'])) {
            foreach ($data['days'] as $day) {
                WorkScheduleDay::create(array_merge(['work_calendar_id' => $calendar->id], $day));
            }
        }

        return response()->json(['success' => true, 'data' => $calendar->load('scheduleDays')], 201);
    }

    #[OA\Get(
        path: '/attendance/calendars/{id}',
        summary: 'Detail Kalender Kerja',
        description: 'Mendapatkan rincian hari kerja kalender.',
        tags: ['Attendance'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Kalender Kerja', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detail kalender kerja ditemukan')
        ]
    )]
    public function showCalendar(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->viewAny($actor)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $calendar = WorkCalendar::with('scheduleDays')->findOrFail($id);

        return response()->json(['success' => true, 'data' => $calendar]);
    }

    #[OA\Put(
        path: '/attendance/calendars/{id}',
        summary: 'Perbarui Kalender Kerja',
        description: 'Memperbarui toleransi keterlambatan dan status kalender kerja.',
        tags: ['Attendance'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Kalender Kerja', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Kalender kerja berhasil diperbarui')
        ]
    )]
    public function updateCalendar(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->manageCalendar($actor)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $calendar = WorkCalendar::findOrFail($id);

        $data = $request->validate([
            'name'                   => 'nullable|string|max:120',
            'description'            => 'nullable|string',
            'late_tolerance_minutes' => 'nullable|integer|min:0|max:120',
            'is_default'             => 'nullable|boolean',
            'is_active'              => 'nullable|boolean',
        ]);

        $calendar->update(array_filter($data, fn($v) => $v !== null));

        return response()->json(['success' => true, 'data' => $calendar->load('scheduleDays')]);
    }

    #[OA\Post(
        path: '/attendance/calendars/{id}/days',
        summary: 'Upsert Jadwal Hari Kerja Kalender',
        description: 'Menyimpan jadwal jam masuk / pulang kerja per hari dalam seminggu.',
        tags: ['Attendance'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Kalender Kerja', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['days'],
                properties: [
                    new OA\Property(property: 'days', type: 'array', items: new OA\Items(type: 'object'))
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Jadwal hari kerja berhasil disimpan')
        ]
    )]
    public function upsertCalendarDays(Request $request, int $calendarId): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->manageCalendar($actor)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $calendar = WorkCalendar::findOrFail($calendarId);

        $data = $request->validate([
            'days'                   => 'required|array|min:1',
            'days.*.day_of_week'     => 'required|integer|min:0|max:6',
            'days.*.is_working_day'  => 'nullable|boolean',
            'days.*.work_start'      => 'nullable|date_format:H:i',
            'days.*.work_end'        => 'nullable|date_format:H:i',
            'days.*.check_in_start'  => 'nullable|date_format:H:i',
            'days.*.check_in_end'    => 'nullable|date_format:H:i',
            'days.*.check_out_start' => 'nullable|date_format:H:i',
        ]);

        foreach ($data['days'] as $day) {
            WorkScheduleDay::updateOrCreate(
                ['work_calendar_id' => $calendar->id, 'day_of_week' => $day['day_of_week']],
                array_merge(['work_calendar_id' => $calendar->id], $day)
            );
        }

        return response()->json(['success' => true, 'data' => $calendar->load('scheduleDays')]);
    }

    #[OA\Get(
        path: '/attendance/holidays',
        summary: 'Daftar Hari Libur Nasional / Perusahaan',
        description: 'Mendapatkan daftar hari libur nasional.',
        tags: ['Attendance'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Daftar hari libur berhasil diambil')
        ]
    )]
    public function holidays(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->viewAny($actor)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $year  = $request->input('year', now()->year);
        $query = PublicHoliday::query()
            ->whereYear('holiday_date', $year)
            ->orderBy('holiday_date');

        return response()->json(['success' => true, 'data' => $query->get()]);
    }

    #[OA\Post(
        path: '/attendance/holidays',
        summary: 'Tambah Hari Libur',
        description: 'Mendaftarkan tanggal hari libur.',
        tags: ['Attendance'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['holiday_date', 'name'],
                properties: [
                    new OA\Property(property: 'holiday_date', type: 'string', format: 'date', example: '2026-12-25'),
                    new OA\Property(property: 'name', type: 'string', example: 'Hari Raya Natal')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Hari libur berhasil didaftarkan')
        ]
    )]
    public function storeHoliday(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->manageHoliday($actor)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $data = $request->validate([
            'holiday_date' => 'required|date',
            'name'         => 'required|string|max:120',
            'type'         => ['nullable', Rule::in(['NATIONAL', 'COMPANY', 'REGIONAL'])],
            'building_id'  => 'nullable|integer|exists:buildings,id',
            'notes'        => 'nullable|string',
        ]);

        $holiday = PublicHoliday::create(array_merge($data, ['created_by' => $actor->id]));

        return response()->json(['success' => true, 'data' => $holiday], 201);
    }

    #[OA\Delete(
        path: '/attendance/holidays/{id}',
        summary: 'Hapus Hari Libur',
        description: 'Menghapus tanggal hari libur.',
        tags: ['Attendance'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Holiday', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Hari libur berhasil dihapus')
        ]
    )]
    public function destroyHoliday(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->manageHoliday($actor)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $holiday = PublicHoliday::findOrFail($id);
        $holiday->delete();

        return response()->json(['success' => true, 'message' => 'Holiday removed.']);
    }

    #[OA\Post(
        path: '/attendance/employees/{id}/assign-calendar',
        summary: 'Alokasikan Kalender Kerja ke Karyawan',
        description: 'Menugaskan kalender jadwal kerja operasional spesifik ke karyawan.',
        tags: ['Attendance'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Karyawan', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['work_calendar_id', 'effective_from'],
                properties: [
                    new OA\Property(property: 'work_calendar_id', type: 'integer', example: 1),
                    new OA\Property(property: 'effective_from', type: 'string', format: 'date', example: '2026-10-01')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Penugasan kalender berhasil disimpan')
        ]
    )]
    public function assignCalendar(Request $request, int $employeeId): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->manageCalendar($actor)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $employee = Employee::findOrFail($employeeId);

        $data = $request->validate([
            'work_calendar_id' => 'required|integer|exists:work_calendars,id',
            'effective_from'   => 'required|date',
            'effective_until'  => 'nullable|date|after_or_equal:effective_from',
        ]);

        EmployeeCalendarAssignment::where('employee_id', $employee->id)
            ->whereNull('effective_until')
            ->where('effective_from', '<', $data['effective_from'])
            ->update(['effective_until' => Carbon::parse($data['effective_from'])->subDay()->toDateString()]);

        $assignment = EmployeeCalendarAssignment::create([
            'employee_id'      => $employee->id,
            'work_calendar_id' => $data['work_calendar_id'],
            'effective_from'   => $data['effective_from'],
            'effective_until'  => $data['effective_until'] ?? null,
            'assigned_by'      => $actor->id,
        ]);

        return response()->json(['success' => true, 'data' => $assignment->load('workCalendar')], 201);
    }

    #[OA\Get(
        path: '/attendance/records',
        summary: 'Daftar Catatan Presensi Kehadiran',
        description: 'Mendapatkan daftar catatan kalkulasi kehadiran karyawan.',
        tags: ['Attendance'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Catatan presensi berhasil diambil')
        ]
    )]
    public function records(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->viewAny($actor) && !$this->policy->self($actor)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $query = Attendance::with([
            'employee:id,name,employee_id,department',
            'workCalendar:id,name,code',
            'accessLogIn:id,door_id,verify_method,timestamp',
            'accessLogIn.door:id,door_id,door_name,name',
            'accessLogOut:id,door_id,verify_method,timestamp',
            'accessLogOut.door:id,door_id,door_name,name',
        ])
            ->orderByDesc('attendance_date');

        if ($this->policy->self($actor) && !$this->policy->viewAny($actor)) {
            $employee = Employee::where('email', $actor->email)->first();
            if ($employee) {
                $query->where('employee_id', $employee->id);
            } else {
                return response()->json(['success' => true, 'data' => []]);
            }
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->input('employee_id'));
        }
        if ($request->filled('date')) {
            $query->where('attendance_date', $request->input('date'));
        }
        if ($request->filled('from')) {
            $query->where('attendance_date', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('attendance_date', '<=', $request->input('to'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $records = $query->paginate($request->input('per_page', 30));

        return response()->json(['success' => true, 'data' => $records]);
    }

    #[OA\Post(
        path: '/attendance/records',
        summary: 'Input/Update Catatan Presensi Manual',
        description: 'Memasukkan atau mengedit catatan presensi karyawan.',
        tags: ['Attendance'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['employee_id', 'attendance_date'],
                properties: [
                    new OA\Property(property: 'employee_id', type: 'integer', example: 1),
                    new OA\Property(property: 'attendance_date', type: 'string', format: 'date', example: '2026-09-17'),
                    new OA\Property(property: 'clock_in_at', type: 'string', format: 'date-time', example: '2026-09-17 08:00:00'),
                    new OA\Property(property: 'status', type: 'string', example: 'PRESENT')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Catatan presensi berhasil disimpan')
        ]
    )]
    public function record(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->create($actor)) {
            return response()->json(['message' => 'Unauthorized to record attendance.'], 403);
        }

        $data = $request->validate([
            'employee_id'         => 'required|integer|exists:employees,id',
            'attendance_date'     => 'required|date',
            'clock_in_at'         => 'nullable|date_format:Y-m-d H:i:s',
            'clock_out_at'        => 'nullable|date_format:Y-m-d H:i:s|after_or_equal:clock_in_at',
            'clock_in_source'     => ['nullable', Rule::in(Attendance::SOURCES)],
            'clock_out_source'    => ['nullable', Rule::in(Attendance::SOURCES)],
            'access_log_in_id'    => 'nullable|integer',
            'access_log_out_id'   => 'nullable|integer',
            'status'              => ['nullable', Rule::in(Attendance::STATUSES)],
            'notes'               => 'nullable|string|max:500',
        ]);

        $employee   = Employee::findOrFail($data['employee_id']);
        $date       = Carbon::parse($data['attendance_date']);
        $data['created_by'] = $actor->id;

        $attendance = $this->processor->record($employee, $date, $data);

        return response()->json(['success' => true, 'data' => $attendance->load(['employee:id,name,employee_id', 'workCalendar:id,name'])], 201);
    }

    #[OA\Get(
        path: '/attendance/records/{id}',
        summary: 'Detail Catatan Presensi',
        description: 'Mendapatkan rincian catatan presensi single.',
        tags: ['Attendance'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Presensi', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Catatan presensi ditemukan')
        ]
    )]
    public function showRecord(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->viewAny($actor) && !$this->policy->self($actor)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $attendance = Attendance::with(['employee:id,name,employee_id,department', 'workCalendar'])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $attendance]);
    }

    #[OA\Post(
        path: '/attendance/records/{id}/verify',
        summary: 'Verifikasi Presensi (HRD)',
        description: 'Verifikasi validasi HRD atas catatan presensi.',
        tags: ['Attendance'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Presensi', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Presensi berhasil diverifikasi')
        ]
    )]
    public function verifyRecord(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->verify($actor)) {
            return response()->json(['message' => 'Unauthorized to verify attendance.'], 403);
        }

        $attendance = Attendance::findOrFail($id);
        $attendance->update([
            'verified_by' => $actor->id,
            'verified_at' => now(),
        ]);

        return response()->json(['success' => true, 'data' => $attendance->fresh()]);
    }

    #[OA\Get(
        path: '/attendance/employees/{id}/summary',
        summary: 'Ringkasan Rekap Presensi Karyawan',
        description: 'Mendapatkan ringkasan statistik kehadiran bulanan per karyawan.',
        tags: ['Attendance'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Karyawan', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Ringkasan presensi berhasil diambil')
        ]
    )]
    public function employeeSummary(Request $request, int $employeeId): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->viewAny($actor) && !$this->policy->self($actor)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $employee = Employee::findOrFail($employeeId);

        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to   = $request->input('to',   now()->toDateString());

        $records = Attendance::where('employee_id', $employee->id)
            ->whereBetween('attendance_date', [$from, $to])
            ->orderBy('attendance_date')
            ->get();

        $summary = $records->groupBy('status')
            ->map(fn($g) => $g->count())
            ->toArray();

        $totalLateMinutes = $records->sum('late_minutes');

        return response()->json([
            'success' => true,
            'data'    => [
                'employee'          => ['id' => $employee->id, 'name' => $employee->name],
                'period'            => ['from' => $from, 'to' => $to],
                'summary'           => $summary,
                'total_late_minutes' => $totalLateMinutes,
                'records'           => $records,
                'active_calendar'   => $this->processor->resolveCalendar($employee, Carbon::parse($to)),
            ],
        ]);
    }
}
