<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * WorkScheduleDay — defines the shift configuration for a specific day-of-week
 * within a WorkCalendar (0=Sunday … 6=Saturday).
 */
class WorkScheduleDay extends Model
{
    use HasFactory;

    protected $table = 'work_schedule_days';

    protected $fillable = [
        'work_calendar_id',
        'day_of_week',
        'is_working_day',
        'check_in_start',
        'check_in_end',
        'check_out_start',
        'work_start',
        'work_end',
    ];

    protected $casts = [
        'is_working_day' => 'boolean',
        'day_of_week'    => 'integer',
    ];

    public function workCalendar()
    {
        return $this->belongsTo(WorkCalendar::class);
    }
}
