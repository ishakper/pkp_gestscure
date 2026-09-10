<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkTask extends Model
{
    use HasFactory;

    public const STATUSES = ['TODO', 'IN_PROGRESS', 'BLOCKED', 'DONE', 'CANCELLED'];
    public const PRIORITIES = ['LOW', 'MEDIUM', 'HIGH', 'URGENT'];

    protected $fillable = ['task_code', 'title', 'description', 'employee_id', 'assigned_by', 'field_assignment_id', 'project_name', 'priority', 'status', 'progress', 'due_date', 'completed_at'];
    protected $casts = ['progress' => 'integer', 'due_date' => 'date', 'completed_at' => 'datetime'];

    public function employee() { return $this->belongsTo(Employee::class); }
    public function assigner() { return $this->belongsTo(Admin::class, 'assigned_by'); }
    public function fieldAssignment() { return $this->belongsTo(FieldAssignment::class); }
    public function worklogs() { return $this->hasMany(TaskWorklog::class); }
}
