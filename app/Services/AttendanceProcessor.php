<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\AccessLog;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeCalendarAssignment;
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
        Carbon $date
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
            $computed = $this->computeStatus($clockIn, $clockOut, $calendar, $date);
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

            $attributes = array_merge([
                'employee_id'             => $employee->id,
                'attendance_date'          => $date->toDateString(),
                'work_calendar_id'         => $calendar?->id,
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
                    $computed = $this->computeStatus(null, null, $calendar, $date);
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
