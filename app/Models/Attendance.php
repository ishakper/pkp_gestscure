<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Attendance — one row per employee per calendar date.
 *
 * Status values (computed by AttendanceProcessor):
 *   PRESENT   — clocked-in on-time
 *   LATE      — clocked-in after work_start + late_tolerance_minutes
 *   ABSENT    — no clock-in on a working day
 *   OFF       — weekend or public holiday
 *   LEAVE     — covered by leave (future Sprint 9-12 link)
 *   HALF_DAY  — partial attendance (future)
 *   WFH       — work from home (future)
 *   FIELD     — field assignment (future)
 *
 * NOTE: access_log_in_id / access_log_out_id are plain integers (NOT foreign keys).
 * AccessLog is an immutable physical device log; this model only references it by ID.
 */
class Attendance extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'employee_id',
        'work_calendar_id',
        'attendance_date',
        'status',
        'clock_in_at',
        'clock_out_at',
        'late_minutes',
        'early_leave_minutes',
        'effective_work_minutes',
        'clock_in_source',
        'clock_out_source',
        'access_log_in_id',
        'access_log_out_id',
        'notes',
        'created_by',
        'verified_by',
        'verified_at',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'clock_in_at'     => 'datetime',
        'clock_out_at'    => 'datetime',
        'verified_at'     => 'datetime',
    ];

    public const STATUSES = [
        'PRESENT',
        'LATE',
        'ABSENT',
        'OFF',
        'LEAVE',
        'HALF_DAY',
        'WFH',
        'FIELD',
    ];

    public const SOURCES = ['MANUAL', 'ACCESS_LOG', 'KIOSK', 'DEVICE'];

    /** Persist calendar days as DATE values on every supported database driver. */
    public function setAttendanceDateAttribute($value): void
    {
        $this->attributes['attendance_date'] = $value ? \Carbon\Carbon::parse($value)->toDateString() : null;
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function workCalendar()
    {
        return $this->belongsTo(WorkCalendar::class);
    }

    /** Immutable device records referenced by the derived attendance row. */
    public function accessLogIn()
    {
        return $this->belongsTo(AccessLog::class, 'access_log_in_id');
    }

    public function accessLogOut()
    {
        return $this->belongsTo(AccessLog::class, 'access_log_out_id');
    }

    /** True if this record has been verified by an admin/HR. */
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /** True if the employee was on time or early. */
    public function isOnTime(): bool
    {
        return in_array($this->status, ['PRESENT', 'WFH', 'FIELD'], true);
    }
}
