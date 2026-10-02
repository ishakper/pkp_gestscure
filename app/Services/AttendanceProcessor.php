<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\AccessLog;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeCalendarAssignment;
use App\Models\AttendanceRequest;
use App\Models\AttendanceCorrectionRequest;
use App\Models\OvertimeRequest;
use App\Models\PublicHoliday;
use App\Models\WorkCalendar;
use App\Models\WorkScheduleDay;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * AttendanceProcessor
 *
 * Central engine for all attendance computation.
 * ALL business rules (late tolerance, work hours, off-day classification) are
 * derived from the WorkCalendar + WorkScheduleDay records — never hardcoded.
 *
 * Vocabulary:
 *  - "calendar date"   : a Carbon date (no time component)
 *  - "clock event"     : a timestamp representing an entry or exit
 *  - "schedule day"    : a WorkScheduleDay row for the relevant day-of-week
 *  - "holiday"         : a PublicHoliday row whose date matches calendar date
 */
class AttendanceProcessor
{
    // -----------------------------------------------------------------------
    // PUBLIC API
    // -----------------------------------------------------------------------

    /**
     * Resolve which WorkCalendar applies to an employee on a specific date.
     * Falls back to the building-default calendar, then the company-wide default.
     */
    public function resolveCalendar(Employee $employee, Carbon $date): ?WorkCalendar
    {
        // 1. Active employee-level assignment
        $assignment = EmployeeCalendarAssignment::query()
            ->where('employee_id', $employee->id)
            ->where('effective_from', '<=', $date->toDateString())
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_until')
                  ->orWhere('effective_until', '>=', $date->toDateString());
            })
            ->orderByDesc('effective_from')
            ->first();

        if ($assignment) {
            return $assignment->workCalendar()->with('scheduleDays')->first();
        }

        // 2. Building-default calendar for employee's building
        if ($employee->building_id) {
            $cal = WorkCalendar::with('scheduleDays')
                ->where('building_id', $employee->building_id)
                ->where('is_default', true)
                ->where('is_active', true)
                ->first();
            if ($cal) return $cal;
        }

        // 3. Company-wide default (building_id = null)
        return WorkCalendar::with('scheduleDays')
            ->whereNull('building_id')
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Determine if a date is a public holiday for the given building scope.
     * building_id = null = company-wide holidays only.
     */
    public function isPublicHoliday(Carbon $date, ?int $buildingId): bool
    {
        return PublicHoliday::query()
            ->whereDate('holiday_date', $date->toDateString())
            ->where(function ($q) use ($buildingId) {
                $q->whereNull('building_id');
                if ($buildingId) {
                    $q->orWhere('building_id', $buildingId);
                }
            })
            ->exists();
    }

    /**
     * Resolve the schedule day definition for a given calendar and date.
     * Returns null if the calendar has no definition for that weekday.
     */
    public function resolveScheduleDay(WorkCalendar $calendar, Carbon $date): ?WorkScheduleDay
    {
        $dow = (int) $date->dayOfWeek; // 0=Sunday … 6=Saturday
        return $calendar->scheduleDays->firstWhere('day_of_week', $dow);
    }

    /**
     * Compute the attendance status for a single employee on a specific date.
     *
     * Parameters:
     *   $clockIn  : Carbon|null — the time the employee clocked in (if any)
     *   $clockOut : Carbon|null — the time the employee clocked out (if any)
     *   $calendar : WorkCalendar|null — resolved for this employee & date
     *   $date     : Carbon — the calendar date
     *
     * Returns an array with keys:
     *   status, late_minutes, early_leave_minutes, effective_work_minutes
     */
    public function computeStatus(
        ?Carbon $clockIn,
        ?Carbon $clockOut,
        ?WorkCalendar $calendar,
        Carbon $date,
        ?Employee $employee = null
    ): array {
        // No calendar → cannot compute; treat as OFF
        if (!$calendar) {
            return $this->offResult();
        }

        $scheduleDay = $this->resolveScheduleDay($calendar, $date);

        // No schedule day entry → treat as OFF (e.g. weekend not in calendar)
        if (!$scheduleDay) {
            return $this->offResult();
        }

        // Public holiday or non-working day in schedule
        if (!$scheduleDay->is_working_day) {
            return $this->offResult();
        }

        $buildingId = $calendar->building_id;
        if ($this->isPublicHoliday($date, $buildingId)) {
            return $this->offResult();
        }

        // Check if employee has an approved AttendanceRequest covering this date
        if ($employee) {
            $approvedRequest = AttendanceRequest::where('employee_id', $employee->id)
                ->where('status', AttendanceRequest::STATUS_APPROVED)
                ->whereDate('start_date', '<=', $date->toDateString())
                ->whereDate('end_date', '>=', $date->toDateString())
                ->first();

            if ($approvedRequest) {
                if ($approvedRequest->request_type === AttendanceRequest::TYPE_LEAVE) {
                    return [
                        'status'                 => 'LEAVE',
                        'late_minutes'           => 0,
                        'early_leave_minutes'    => 0,
                        'effective_work_minutes' => 0,
                    ];
                }
                if ($approvedRequest->request_type === AttendanceRequest::TYPE_SICK) {
                    return [
                        'status'                 => 'SICK',
                        'late_minutes'           => 0,
                        'early_leave_minutes'    => 0,
                        'effective_work_minutes' => 0,
                    ];
                }
                if ($approvedRequest->request_type === AttendanceRequest::TYPE_WFH) {
                    if (!$clockIn) {
                        return [
                            'status'                 => 'WFH',
                            'late_minutes'           => 0,
                            'early_leave_minutes'    => 0,
                            'effective_work_minutes' => 0,
                        ];
                    }
                }
                if ($approvedRequest->request_type === AttendanceRequest::TYPE_PERMISSION) {
                    if (!$clockIn) {
                        return [
                            'status'                 => 'PERMISSION',
                            'late_minutes'           => 0,
                            'early_leave_minutes'    => 0,
                            'effective_work_minutes' => 0,
                        ];
                    }
                }
            }
        }

        // Working day but no clock-in → ABSENT
        if (!$clockIn) {
            return [
                'status'                 => 'ABSENT',
                'late_minutes'           => 0,
                'early_leave_minutes'    => 0,
                'effective_work_minutes' => 0,
            ];
        }

        // Compute late minutes
        $lateMinutes = 0;
        if ($scheduleDay->work_start) {
            $nominalStart = Carbon::parse($date->toDateString() . ' ' . $scheduleDay->work_start);
            $lateTolerance = (int) $calendar->late_tolerance_minutes; // CONFIG-DRIVEN
            $cutoff = $nominalStart->copy()->addMinutes($lateTolerance);

            if ($clockIn->gt($cutoff)) {
                $lateMinutes = (int) $clockIn->diffInMinutes($nominalStart);
            }
        }

        // Compute early leave minutes
        $earlyLeaveMinutes = 0;
        if ($clockOut && $scheduleDay->work_end) {
            $nominalEnd = Carbon::parse($date->toDateString() . ' ' . $scheduleDay->work_end);
            if ($clockOut->lt($nominalEnd)) {
                $earlyLeaveMinutes = (int) $clockOut->diffInMinutes($nominalEnd);
            }
        }

        // Compute effective work minutes
        $effectiveWorkMinutes = 0;
        if ($clockOut) {
            $effectiveWorkMinutes = max(0, (int) $clockIn->diffInMinutes($clockOut));
        }

        $status = $lateMinutes > 0 ? 'LATE' : 'PRESENT';


        return [
            'status'                 => $status,
            'late_minutes'           => $lateMinutes,
            'early_leave_minutes'    => $earlyLeaveMinutes,
            'effective_work_minutes' => $effectiveWorkMinutes,
        ];
    }

    /**
     * Record or update an attendance for an employee on a specific date.
     *
     * @param Employee   $employee
     * @param Carbon     $date
     * @param array      $data   May contain: clock_in_at, clock_out_at, clock_in_source,
     *                           clock_out_source, access_log_in_id, access_log_out_id,
     *                           notes, status (override), created_by
     * @return Attendance
     */
    public function record(Employee $employee, Carbon $date, array $data): Attendance
    {
        $calendar = $this->resolveCalendar($employee, $date);

        $clockIn  = isset($data['clock_in_at'])  ? Carbon::parse($data['clock_in_at'])  : null;
        $clockOut = isset($data['clock_out_at']) ? Carbon::parse($data['clock_out_at']) : null;

        // If an explicit status override is passed, respect it
        if (isset($data['status'])) {
            $computed = [
                'status'                 => $data['status'],
                'late_minutes'           => $data['late_minutes'] ?? 0,
                'early_leave_minutes'    => $data['early_leave_minutes'] ?? 0,
                'effective_work_minutes' => $data['effective_work_minutes'] ?? 0,
            ];
        } else {
            $computed = $this->computeStatus($clockIn, $clockOut, $calendar, $date, $employee);
        }

        return DB::transaction(function () use ($employee, $date, $data, $calendar, $clockIn, $clockOut, $computed) {
            // `attendance_date` is a DATE column. whereDate keeps lookups stable
            // across SQLite/MySQL serialization differences and avoids duplicate rows.
            $attendance = Attendance::withTrashed()
                ->where('employee_id', $employee->id)
                ->whereDate('attendance_date', $date->toDateString())
                ->first();

            if ($attendance?->trashed()) {
                $attendance->restore();
            }

            $attendanceType = $data['attendance_type'] ?? (
                (($data['clock_in_source'] ?? '') === 'FIELD' || ($data['clock_out_source'] ?? '') === 'FIELD')
                    ? 'FIELD'
                    : ($attendance?->attendance_type ?? 'OFFICE')
            );

            $attributes = array_merge([
                'employee_id'             => $employee->id,
                'attendance_date'          => $date->toDateString(),
                'work_calendar_id'         => $calendar?->id,
                'attendance_type'          => $attendanceType,
                'clock_in_at'              => $clockIn,
                'clock_out_at'             => $clockOut,
                'clock_in_source'          => $data['clock_in_source']  ?? 'MANUAL',
                'clock_out_source'         => $data['clock_out_source'] ?? 'MANUAL',
                'access_log_in_id'         => $data['access_log_in_id']  ?? null,
                'access_log_out_id'        => $data['access_log_out_id'] ?? null,
                'notes'                    => $data['notes'] ?? null,
                'created_by'               => $data['created_by'] ?? null,
            ], $computed);

            if ($attendance) {
                $attendance->fill($attributes)->save();
                return $attendance;
            }

            return Attendance::create($attributes);
        });
    }

    /**
     * Process a verified FieldAttendanceEvidence record to update the daily Attendance
     * with attendance_type = FIELD.
     */
    public function processFieldEvidence(\App\Models\FieldAttendanceEvidence $evidence): ?Attendance
    {
        if (!$evidence->employee_id || !$evidence->isVerified()) {
            return null;
        }

        $employee = \App\Models\Employee::find($evidence->employee_id);
        if (!$employee) return null;

        $date = $evidence->attendance_date ? Carbon::parse($evidence->attendance_date) : $evidence->captured_at->copy()->startOfDay();

        // Fetch existing attendance for the day
        $attendance = Attendance::where('employee_id', $employee->id)
            ->whereDate('attendance_date', $date->toDateString())
            ->first();

        $clockIn = $attendance?->clock_in_at;
        $clockOut = $attendance?->clock_out_at;
        $sourceIn = $attendance?->clock_in_source ?? 'FIELD';
        $sourceOut = $attendance?->clock_out_source ?? 'FIELD';

        if ($evidence->type === 'CHECK_IN') {
            if (!$clockIn) {
                $clockIn = $evidence->captured_at;
                $sourceIn = 'FIELD';
            }
        } elseif ($evidence->type === 'CHECK_OUT') {
            $clockOut = $evidence->captured_at;
            $sourceOut = 'FIELD';
        }

        $result = $this->record($employee, $date, [
            'clock_in_at' => $clockIn?->toDateTimeString(),
            'clock_out_at' => $clockOut?->toDateTimeString(),
            'clock_in_source' => $sourceIn,
            'clock_out_source' => $sourceOut,
            'attendance_type' => 'FIELD',
            'notes' => $attendance?->notes ?? ('Presensi Lapangan: ' . ($evidence->fieldLocation?->name ?? 'Lokasi')),
        ]);

        if ($result && $evidence->attendance_id !== $result->id) {
            $evidence->attendance_id = $result->id;
            $evidence->save();
        }

        return $result;
    }
    /**
     * Process an AttendanceEvidence record from a physical access log
     * to update the daily Attendance check-in or check-out.
     */
    public function processEvidence(\App\Models\AttendanceEvidence $evidence): ?Attendance
    {
        if (!$evidence->employee_id || $evidence->status !== 'MAPPED') {
            return null;
        }

        $employee = \App\Models\Employee::find($evidence->employee_id);
        if (!$employee) return null;

        $date = $evidence->event_timestamp->copy()->startOfDay();
        $calendar = $this->resolveCalendar($employee, $date);

        // Fetch existing attendance for the day
        $attendance = Attendance::where('employee_id', $employee->id)
            ->where('attendance_date', $date->toDateString())
            ->first();

        $clockIn = $attendance?->clock_in_at;
        $clockOut = $attendance?->clock_out_at;
        $accessLogInId = $attendance?->access_log_in_id;
        $accessLogOutId = $attendance?->access_log_out_id;
        $sourceIn = $attendance?->clock_in_source ?? 'DEVICE';
        $sourceOut = $attendance?->clock_out_source ?? 'DEVICE';

        $isEntry = false;
        $isExit = false;

        // Direction is device/config supplied. UNKNOWN may safely establish a
        // first check-in, but can never create a check-out based on scan order.
        if ($evidence->direction === 'UNKNOWN') {
            if (!$clockIn) {
                $isEntry = true;
            }
        } elseif ($evidence->direction === 'ENTRY') {
            $isEntry = true;
        } elseif ($evidence->direction === 'EXIT') {
            $isExit = true;
        }

        if ($isEntry && !$clockIn) {
            $clockIn = $evidence->event_timestamp;
            $accessLogInId = $evidence->access_log_id;
            $sourceIn = 'DEVICE';
        }

        if ($isExit) {
            // Overwrite latest checkout
            $clockOut = $evidence->event_timestamp;
            $accessLogOutId = $evidence->access_log_id;
            $sourceOut = 'DEVICE';
        }

        // If neither resolved (e.g. rapid double tap UNKNOWN), do nothing
        if (!$isEntry && !$isExit) {
            return $attendance;
        }

        return $this->record($employee, $date, [
            'clock_in_at' => $clockIn?->toDateTimeString(),
            'clock_out_at' => $clockOut?->toDateTimeString(),
            'clock_in_source' => $sourceIn,
            'clock_out_source' => $sourceOut,
            'access_log_in_id' => $accessLogInId,
            'access_log_out_id' => $accessLogOutId,
        ]);
    }

    /**
     * Bulk-generate OFF / ABSENT skeleton rows for a date range for a set of employees.
     * Used by cron/batch reconciliation. Does NOT overwrite rows that already exist.
     */
    public function generateSkeletons(Collection $employees, Carbon $from, Carbon $to): int
    {
        $created = 0;
        $date = $from->copy();

        while ($date->lte($to)) {
            foreach ($employees as $employee) {
                $existing = Attendance::where('employee_id', $employee->id)
                    ->where('attendance_date', $date->toDateString())
                    ->exists();

                if (!$existing) {
                    $calendar = $this->resolveCalendar($employee, $date);
                    $computed = $this->computeStatus(null, null, $calendar, $date, $employee);
                    Attendance::create([
                        'employee_id'            => $employee->id,
                        'work_calendar_id'       => $calendar?->id,
                        'attendance_date'        => $date->toDateString(),
                        'status'                 => $computed['status'],
                        'late_minutes'           => 0,
                        'early_leave_minutes'    => 0,
                        'effective_work_minutes' => 0,
                        'clock_in_source'        => 'MANUAL',
                        'clock_out_source'       => 'MANUAL',
                    ]);
                    $created++;
                }
            }
            $date->addDay();
        }

        return $created;
    }

    /**
     * The first date each employee can defensibly be expected at work, from existing
     * per-employee facts only (read-only):
     *   1. employees.hire_date, when set;
     *   2. otherwise the earliest of
     *      - start (effective_date, else start_date) of a contract that actually took
     *        effect (ACTIVE / EXPIRED / TERMINATED / RENEWED),
     *      - effective_from of an explicit calendar assignment,
     *      - the employee's first attendance row.
     * employees.created_at is deliberately not used: it records when the row was
     * created or imported, not when the person started work. Employees with none of
     * the above are omitted, so no absence is derived for them.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array<int, array{date: string, source: string}>  employee id => eligibility start
     */
    public function absenceEligibilityStarts(Collection $employees): array
    {
        $ids = $employees->pluck('id')->all();
        if (!$ids) {
            return [];
        }

        $evidence = [];
        $add = function ($employeeId, $date, string $source) use (&$evidence): void {
            if (!$date) return;
            $date = Carbon::parse($date)->toDateString();
            if (!isset($evidence[$employeeId]) || $date < $evidence[$employeeId]['date']) {
                $evidence[$employeeId] = ['date' => $date, 'source' => $source];
            }
        };

        \App\Models\Contract::query()
            ->whereIn('employee_id', $ids)
            ->whereIn('status', ['ACTIVE', 'EXPIRED', 'TERMINATED', 'RENEWED'])
            ->get(['employee_id', 'start_date', 'effective_date'])
            ->each(fn ($contract) => $add($contract->employee_id, $contract->effective_date ?? $contract->start_date, 'contract'));
        EmployeeCalendarAssignment::query()
            ->whereIn('employee_id', $ids)
            ->get(['employee_id', 'effective_from'])
            ->each(fn ($assignment) => $add($assignment->employee_id, $assignment->effective_from, 'calendar_assignment'));
        Attendance::query()
            ->whereIn('employee_id', $ids)
            ->groupBy('employee_id')
            ->selectRaw('employee_id, MIN(attendance_date) as first_date')
            ->get()
            ->each(fn ($row) => $add($row->employee_id, $row->first_date, 'first_attendance'));

        $starts = [];
        foreach ($employees as $employee) {
            if ($employee->hire_date) {
                $starts[$employee->id] = ['date' => Carbon::parse($employee->hire_date)->toDateString(), 'source' => 'hire_date'];
            } elseif (isset($evidence[$employee->id])) {
                $starts[$employee->id] = $evidence[$employee->id];
            }
        }

        return $starts;
    }

    /**
     * Count, per employee, the working days in [$from, $to] that have no attendance
     * row at all. computeStatus() would classify each of those days as ABSENT, but
     * nothing persists ABSENT rows (generateSkeletons() is not scheduled), so reports
     * must derive them. Read-only: no rows are written.
     *
     * Applies the same rules as resolveCalendar() / computeStatus() with no clock-in:
     * assignment > building default > company default calendar, schedule working day,
     * public holiday for the calendar's building, approved LEAVE/SICK/WFH/PERMISSION.
     * Inputs are preloaded once so a month of ~100 employees stays a handful of queries.
     *
     * Only days on or after the employee's eligibility start count; employees
     * without one (see absenceEligibilityStarts()) get no derived absence at all.
     *
     * @param  Collection<int, Employee>  $employees
     * @param  array<int, array<string, true>>  $recordedDates  employee id => ['Y-m-d' => true]
     * @param  array<int, array{date: string, source: string}>  $eligibility  employee id => start
     * @return array<int, int>  employee id => derived absent days (only non-zero entries)
     */
    public function derivedAbsences(Collection $employees, Carbon $from, Carbon $to, array $recordedDates, array $eligibility): array
    {
        $employees = $employees->filter(fn (Employee $employee) => isset($eligibility[$employee->id]))->values();

        if ($employees->isEmpty() || $from->gt($to)) {
            return [];
        }

        $employeeIds = $employees->pluck('id')->all();
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $calendars = WorkCalendar::with('scheduleDays')->get()->keyBy('id');
        $buildingDefaults = $calendars
            ->filter(fn (WorkCalendar $calendar) => $calendar->is_default && $calendar->is_active)
            ->keyBy(fn (WorkCalendar $calendar) => (string) $calendar->building_id);
        $assignments = EmployeeCalendarAssignment::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('effective_from', '<=', $toDate)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $fromDate))
            ->orderByDesc('effective_from')
            ->get()
            ->groupBy('employee_id');
        $holidays = PublicHoliday::query()
            ->whereBetween('holiday_date', [$fromDate, $toDate.' 23:59:59'])
            ->get(['holiday_date', 'building_id'])
            ->map(fn (PublicHoliday $holiday) => [Carbon::parse($holiday->holiday_date)->toDateString(), $holiday->building_id]);
        $approvedRequests = AttendanceRequest::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', AttendanceRequest::STATUS_APPROVED)
            ->whereIn('request_type', [
                AttendanceRequest::TYPE_LEAVE, AttendanceRequest::TYPE_SICK,
                AttendanceRequest::TYPE_WFH, AttendanceRequest::TYPE_PERMISSION,
            ])
            ->whereDate('start_date', '<=', $toDate)
            ->whereDate('end_date', '>=', $fromDate)
            ->get(['employee_id', 'start_date', 'end_date'])
            ->groupBy('employee_id');

        $absences = [];
        foreach ($employees as $employee) {
            $eligibleFrom = $eligibility[$employee->id]['date'];
            $count = 0;

            for ($date = $from->copy()->startOfDay(); $date->lte($to); $date->addDay()) {
                $day = $date->toDateString();
                if ($day < $eligibleFrom || isset($recordedDates[$employee->id][$day])) {
                    continue;
                }

                $assignment = ($assignments[$employee->id] ?? collect())->first(fn ($a) =>
                    Carbon::parse($a->effective_from)->toDateString() <= $day
                    && (!$a->effective_until || Carbon::parse($a->effective_until)->toDateString() >= $day));
                $calendar = $assignment
                    ? $calendars->get($assignment->work_calendar_id)
                    : ($buildingDefaults->get((string) $employee->building_id) ?? $buildingDefaults->get(''));
                if (!$calendar) {
                    continue;
                }

                $scheduleDay = $this->resolveScheduleDay($calendar, $date);
                if (!$scheduleDay || !$scheduleDay->is_working_day) {
                    continue;
                }
                if ($holidays->contains(fn ($h) => $h[0] === $day && ($h[1] === null || (int) $h[1] === (int) $calendar->building_id))) {
                    continue;
                }
                if (($approvedRequests[$employee->id] ?? collect())->contains(fn ($r) =>
                    Carbon::parse($r->start_date)->toDateString() <= $day && Carbon::parse($r->end_date)->toDateString() >= $day)) {
                    continue;
                }

                $count++;
            }

            if ($count > 0) {
                $absences[$employee->id] = $count;
            }
        }

        return $absences;
    }

    /**
     * Integrate an approved AttendanceRequest (WFH, LEAVE, PERMISSION, SICK)
     * into Attendance daily records across its entire date range.
     */
    public function integrateApprovedRequest(AttendanceRequest $request): void
    {
        $employee = $request->employee;
        if (!$employee) {
            return;
        }

        $start = Carbon::parse($request->start_date)->startOfDay();
        $end   = Carbon::parse($request->end_date)->startOfDay();
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $calendar = $this->resolveCalendar($employee, $cursor);
            $scheduleDay = $calendar ? $this->resolveScheduleDay($calendar, $cursor) : null;
            $isHoliday = $calendar ? $this->isPublicHoliday($cursor, $calendar->building_id) : false;

            // Do not convert non-working days / holidays to leave/sick/WFH
            if ($scheduleDay && $scheduleDay->is_working_day && !$isHoliday) {
                $attendance = Attendance::where('employee_id', $employee->id)
                    ->whereDate('attendance_date', $cursor->toDateString())
                    ->first();

                $targetStatus = match ($request->request_type) {
                    AttendanceRequest::TYPE_LEAVE      => 'LEAVE',
                    AttendanceRequest::TYPE_SICK       => 'SICK',
                    AttendanceRequest::TYPE_PERMISSION => 'PERMISSION',
                    AttendanceRequest::TYPE_WFH        => 'WFH',
                    default                            => 'PRESENT',
                };

                $notePrefix = match ($request->request_type) {
                    AttendanceRequest::TYPE_LEAVE      => 'Cuti: ',
                    AttendanceRequest::TYPE_SICK       => 'Sakit: ',
                    AttendanceRequest::TYPE_PERMISSION => 'Izin: ',
                    AttendanceRequest::TYPE_WFH        => 'WFH: ',
                    default                            => 'Permohonan: ',
                };

                if (!$attendance) {
                    Attendance::create([
                        'employee_id'            => $employee->id,
                        'work_calendar_id'       => $calendar?->id,
                        'attendance_date'        => $cursor->toDateString(),
                        'status'                 => $targetStatus,
                        'attendance_type'        => $request->request_type === AttendanceRequest::TYPE_WFH ? 'WFH' : 'OFFICE',
                        'late_minutes'           => 0,
                        'early_leave_minutes'    => 0,
                        'effective_work_minutes' => 0,
                        'clock_in_source'        => 'MANUAL',
                        'clock_out_source'       => 'MANUAL',
                        'notes'                  => $notePrefix . $request->reason,
                        'created_by'             => $request->approved_by,
                    ]);
                } else {
                    // Update existing row
                    $updates = [];
                    if (in_array($attendance->status, ['ABSENT', 'OFF'], true)) {
                        $updates['status'] = $targetStatus;
                    }
                    if ($request->request_type === AttendanceRequest::TYPE_WFH) {
                        $updates['attendance_type'] = 'WFH';
                        if ($attendance->status === 'ABSENT') {
                            $updates['status'] = 'WFH';
                        }
                    }
                    $updates['notes'] = trim(($attendance->notes ? $attendance->notes . ' | ' : '') . $notePrefix . $request->reason);
                    $attendance->update($updates);
                }
            }

            $cursor->addDay();
        }
    }

    /**
     * Apply an approved AttendanceCorrectionRequest to the derived Attendance record.
     * IMMUTABILITY INVARIANT: RAW physical access logs and evidences are never altered.
     * Only the derived attendance record is adjusted with explicit provenance marked MANUAL.
     */
    public function applyApprovedCorrection(AttendanceCorrectionRequest $correction): Attendance
    {
        $employee = $correction->employee;
        $date = Carbon::parse($correction->correction_date)->startOfDay();
        $calendar = $this->resolveCalendar($employee, $date);

        $attendance = Attendance::where('employee_id', $employee->id)
            ->whereDate('attendance_date', $date->toDateString())
            ->first();

        // Capture immutable original baseline if not set yet
        if (!$correction->original_check_in && $attendance?->clock_in_at) {
            $correction->original_check_in = $attendance->clock_in_at;
        }
        if (!$correction->original_check_out && $attendance?->clock_out_at) {
            $correction->original_check_out = $attendance->clock_out_at;
        }
        if (!$correction->original_status && $attendance?->status) {
            $correction->original_status = $attendance->status;
        }
        if (!$correction->original_attendance_type && $attendance?->attendance_type) {
            $correction->original_attendance_type = $attendance->attendance_type;
        }

        // Determine corrected values
        $clockIn  = $correction->requested_check_in  ?? $attendance?->clock_in_at;
        $clockOut = $correction->requested_check_out ?? $attendance?->clock_out_at;
        $attType  = $correction->requested_attendance_type ?? $attendance?->attendance_type ?? 'OFFICE';

        if ($correction->requested_status) {
            $status = $correction->requested_status;
            $lateMinutes = $attendance?->late_minutes ?? 0;
            $earlyLeaveMinutes = $attendance?->early_leave_minutes ?? 0;
            $effectiveWorkMinutes = $attendance?->effective_work_minutes ?? 0;
        } elseif ($clockIn) {
            $computed = $this->computeStatus($clockIn, $clockOut, $calendar, $date, $employee);
            $status = $computed['status'];
            $lateMinutes = $computed['late_minutes'];
            $earlyLeaveMinutes = $computed['early_leave_minutes'];
            $effectiveWorkMinutes = $computed['effective_work_minutes'];
        } else {
            $status = $attendance?->status ?? 'PRESENT';
            $lateMinutes = 0;
            $earlyLeaveMinutes = 0;
            $effectiveWorkMinutes = 0;
        }

        $provenanceNote = '[KOREKSI_MANUAL: ' . ($correction->reason ?? 'Disetujui') . ']';

        $recordData = [
            'clock_in_at'            => $clockIn?->toDateTimeString(),
            'clock_out_at'           => $clockOut?->toDateTimeString(),
            'status'                 => $status,
            'attendance_type'        => $attType,
            'late_minutes'           => $lateMinutes,
            'early_leave_minutes'    => $earlyLeaveMinutes,
            'effective_work_minutes' => $effectiveWorkMinutes,
            'clock_in_source'        => $correction->requested_check_in ? 'MANUAL' : ($attendance?->clock_in_source ?? 'MANUAL'),
            'clock_out_source'       => $correction->requested_check_out ? 'MANUAL' : ($attendance?->clock_out_source ?? 'MANUAL'),
            'notes'                  => trim(($attendance?->notes ? $attendance->notes . ' | ' : '') . $provenanceNote),
            'verified_by'            => $correction->approved_by,
            'verified_at'            => now(),
        ];

        // Maintain physical access log IDs if already linked (NEVER mutate AccessLog)
        if ($attendance?->access_log_in_id) {
            $recordData['access_log_in_id'] = $attendance->access_log_in_id;
        }
        if ($attendance?->access_log_out_id) {
            $recordData['access_log_out_id'] = $attendance->access_log_out_id;
        }

        $updatedAttendance = $this->record($employee, $date, $recordData);

        // Snapshot corrected outcome
        $correction->corrected_check_in         = $updatedAttendance->clock_in_at;
        $correction->corrected_check_out        = $updatedAttendance->clock_out_at;
        $correction->corrected_status           = $updatedAttendance->status;
        $correction->corrected_attendance_type  = $updatedAttendance->attendance_type;
        $correction->attendance_id              = $updatedAttendance->id;
        $correction->save();

        return $updatedAttendance;
    }

    /**
     * Apply an approved OvertimeRequest to the derived Attendance record.
     */
    public function applyApprovedOvertime(OvertimeRequest $overtime): Attendance
    {
        $employee = $overtime->employee;
        $date = Carbon::parse($overtime->overtime_date)->startOfDay();

        $attendance = Attendance::where('employee_id', $employee->id)
            ->whereDate('attendance_date', $date->toDateString())
            ->first();

        if (!$attendance) {
            $calendar = $this->resolveCalendar($employee, $date);
            $scheduleDay = $calendar ? $this->resolveScheduleDay($calendar, $date) : null;
            $isHoliday = $calendar ? $this->isPublicHoliday($date, $calendar->building_id) : false;
            $status = ($scheduleDay && !$scheduleDay->is_working_day) || $isHoliday ? 'OFF' : 'PRESENT';

            $attendance = Attendance::create([
                'employee_id'            => $employee->id,
                'work_calendar_id'       => $calendar?->id,
                'attendance_date'        => $date->toDateString(),
                'status'                 => $status,
                'attendance_type'        => 'OFFICE',
                'overtime_minutes'       => (int) $overtime->approved_minutes,
                'clock_in_source'        => 'MANUAL',
                'clock_out_source'       => 'MANUAL',
                'notes'                  => '[LEMBUR_DISETUJUI: ' . $overtime->approved_minutes . ' menit - ' . $overtime->reason . ']',
                'verified_by'            => $overtime->approved_by,
                'verified_at'            => now(),
            ]);
        } else {
            $attendance->overtime_minutes = (int) $overtime->approved_minutes;
            $overtimeNote = '[LEMBUR_DISETUJUI: ' . $overtime->approved_minutes . ' menit]';
            if (!str_contains((string) $attendance->notes, '[LEMBUR_DISETUJUI:')) {
                $attendance->notes = trim(($attendance->notes ? $attendance->notes . ' | ' : '') . $overtimeNote);
            }
            $attendance->save();
        }

        if ($overtime->attendance_id !== $attendance->id) {
            $overtime->attendance_id = $attendance->id;
            $overtime->save();
        }

        return $attendance;
    }

    /**
     * Get attendance summary metrics for the given scope.
     */
    public function getMetrics(Admin $actor): array
    {
        $today      = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();

        $todayStats = Attendance::query()
            ->where('attendance_date', $today)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->toArray();

        $monthStats = Attendance::query()
            ->whereBetween('attendance_date', [$monthStart, $today])
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->toArray();
        $activeEmployeeCount = Employee::query()
            ->where(function ($query) {
                $query->where('employment_status', 'ACTIVE')->orWhereNull('employment_status');
            })->count();
        $todayCheckoutCount = Attendance::where('attendance_date', $today)
            ->whereNotNull('clock_out_at')
            ->count();
        // Live office presence: clocked in today, and clocked out not yet.
        $todayCheckedIn = Attendance::where('attendance_date', $today)->whereNotNull('clock_in_at');
        $checkedInToday = (clone $todayCheckedIn)->count();
        $inOfficeNow = (clone $todayCheckedIn)->whereNull('clock_out_at')->count();

        $latestDeviceEvent = AccessLog::query()
            ->with('door:id,door_id,door_name,name')
            ->whereDate('timestamp', $today)
            ->orderByDesc('timestamp')
            ->first();

        return [
            'today' => [
                'date'    => $today,
                'present' => $todayStats['PRESENT'] ?? 0,
                'late'    => $todayStats['LATE'] ?? 0,
                'absent'  => $todayStats['ABSENT'] ?? 0,
                'off'     => $todayStats['OFF'] ?? 0,
                'leave'   => $todayStats['LEAVE'] ?? 0,
                'checkout' => $todayCheckoutCount,
                'total_employees' => $activeEmployeeCount,
            ],
            'month' => [
                'from'    => $monthStart,
                'to'      => $today,
                'present' => ($monthStats['PRESENT'] ?? 0) + ($monthStats['LATE'] ?? 0),
                'late'    => $monthStats['LATE'] ?? 0,
                'absent'  => $monthStats['ABSENT'] ?? 0,
                'off'     => $monthStats['OFF'] ?? 0,
            ],
            'calendars_count' => WorkCalendar::where('is_active', true)->count(),
            'live' => [
                'latest_event_at' => $latestDeviceEvent?->timestamp?->toIso8601String(),
                'latest_door' => $latestDeviceEvent?->door?->door_name,
                'latest_status' => $latestDeviceEvent?->access_status,
                'in_office_now' => $inOfficeNow,
                'checked_in_today' => $checkedInToday,
                'active_employees' => $activeEmployeeCount,
                'unmatched_events' => AccessLog::query()
                    ->whereDate('timestamp', $today)
                    ->where(function ($query) {
                        $query->whereNull('employee_id')->orWhere('access_status', 'Denied');
                    })->count(),
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // PRIVATE HELPERS
    // -----------------------------------------------------------------------

    private function offResult(): array
    {
        return [
            'status'                 => 'OFF',
            'late_minutes'           => 0,
            'early_leave_minutes'    => 0,
            'effective_work_minutes' => 0,
        ];
    }
}
