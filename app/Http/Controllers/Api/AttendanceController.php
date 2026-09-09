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

/**
 * AttendanceController
 *
 * Endpoints:
 *  GET    /api/v1/attendance/metrics                   — dashboard KPIs
 *  GET    /api/v1/attendance/calendars                 — list work calendars
 *  POST   /api/v1/attendance/calendars                 — create work calendar
 *  GET    /api/v1/attendance/calendars/{id}            — show calendar detail
 *  PUT    /api/v1/attendance/calendars/{id}            — update calendar
 *  POST   /api/v1/attendance/calendars/{id}/days       — upsert schedule day(s)
 *  GET    /api/v1/attendance/holidays                  — list public holidays
 *  POST   /api/v1/attendance/holidays                  — create public holiday
 *  DELETE /api/v1/attendance/holidays/{id}             — remove holiday
 *  POST   /api/v1/attendance/employees/{id}/assign-calendar — assign calendar to employee
 *  GET    /api/v1/attendance/records                   — list attendance records (scoped)
 *  POST   /api/v1/attendance/records                   — record/update attendance
 *  GET    /api/v1/attendance/records/{id}              — show single record
 *  POST   /api/v1/attendance/records/{id}/verify       — HR verification
 *  GET    /api/v1/attendance/employees/{id}/summary    — employee attendance summary
 */
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

    // -----------------------------------------------------------------------
    // METRICS
    // -----------------------------------------------------------------------

    public function metrics(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->viewAny($actor)) {
            return response()->json(['message' => 'Unauthorized to view attendance metrics.'], 403);
        }
        return response()->json(['success' => true, 'data' => $this->processor->getMetrics($actor)]);
    }

    // -----------------------------------------------------------------------
    // WORK CALENDARS
    // -----------------------------------------------------------------------

    public function calendars(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->viewAny($actor)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $query = WorkCalendar::with('scheduleDays')->where('is_active', true);

        return response()->json(['success' => true, 'data' => $query->get()]);
    }

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

    public function showCalendar(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->viewAny($actor)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $calendar = WorkCalendar::with('scheduleDays')->findOrFail($id);

        return response()->json(['success' => true, 'data' => $calendar]);
    }

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

    // -----------------------------------------------------------------------
    // PUBLIC HOLIDAYS
    // -----------------------------------------------------------------------

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

    // -----------------------------------------------------------------------
    // EMPLOYEE CALENDAR ASSIGNMENTS
    // -----------------------------------------------------------------------

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

        // Close any open assignment first
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

    // -----------------------------------------------------------------------
    // ATTENDANCE RECORDS
    // -----------------------------------------------------------------------

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

        // Self-service: employees see only their own
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

        // Building scope for management portal users — if actor has assigned_building,
        // only their employees are included (resolved via Employee.building_id)
        // This is a stub for now; fine-grained building scope can be added in a later sprint
        // when Admin gains a building_id FK.

        $records = $query->paginate($request->input('per_page', 30));

        return response()->json(['success' => true, 'data' => $records]);
    }

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

    public function showRecord(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->viewAny($actor) && !$this->policy->self($actor)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $attendance = Attendance::with(['employee:id,name,employee_id,department', 'workCalendar'])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $attendance]);
    }

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
