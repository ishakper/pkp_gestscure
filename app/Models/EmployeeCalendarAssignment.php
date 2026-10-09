<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * EmployeeCalendarAssignment — binds an employee to a WorkCalendar for a date range.
 * effective_until = null means indefinite (current assignment).
 */
class EmployeeCalendarAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'work_calendar_id',
        'effective_from',
        'effective_until',
        'assigned_by',
    ];

    protected $casts = [
        'effective_from'  => 'date',
        'effective_until' => 'date',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function workCalendar()
    {
        return $this->belongsTo(WorkCalendar::class);
    }
}
