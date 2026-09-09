<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * WorkCalendar — a named schedule template with late-tolerance and shift definitions.
 * All business rules (work hours, late tolerance) are stored here, NOT hardcoded.
 */
class WorkCalendar extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'description',
        'building_id',
        'late_tolerance_minutes',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active'  => 'boolean',
    ];

    public function scheduleDays()
    {
        return $this->hasMany(WorkScheduleDay::class);
    }

    public function employeeAssignments()
    {
        return $this->hasMany(EmployeeCalendarAssignment::class);
    }

    public function publicHolidays()
    {
        return $this->hasMany(PublicHoliday::class, 'building_id', 'building_id');
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    /** Returns the schedule day definition for a given 0-6 day of week, or null if not set. */
    public function getDaySchedule(int $dayOfWeek): ?WorkScheduleDay
    {
        return $this->scheduleDays->firstWhere('day_of_week', $dayOfWeek);
    }
}
