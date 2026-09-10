<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaskWorklog extends Model
{
    use HasFactory;

    protected $fillable = ['work_task_id', 'employee_id', 'work_date', 'duration_minutes', 'notes'];
    protected $casts = ['work_date' => 'date', 'duration_minutes' => 'integer'];

    public function task() { return $this->belongsTo(WorkTask::class, 'work_task_id'); }
    public function employee() { return $this->belongsTo(Employee::class); }
}
