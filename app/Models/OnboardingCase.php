<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OnboardingCase extends Model
{
    use HasFactory;

    protected $fillable = [
        'case_number',
        'employee_id',
        'internship_id',
        'candidate_id',
        'status',
        'start_date',
        'target_completion_date',
        'hr_owner_id',
        'supervisor_id',
        'division_id',
        'position_id',
        'employment_type',
        'work_location',
        'completed_at',
        'completed_by',
        'notes',
    ];

    protected $casts = [
        'start_date' => 'date',
        'target_completion_date' => 'date',
        'completed_at' => 'datetime',
    ];

    public function employee() { return $this->belongsTo(Employee::class); }
    public function internship() { return $this->belongsTo(Internship::class); }
    public function candidate() { return $this->belongsTo(Candidate::class); }
    public function hrOwner() { return $this->belongsTo(Admin::class, 'hr_owner_id'); }
    public function supervisor() { return $this->belongsTo(Employee::class, 'supervisor_id'); }
    public function division() { return $this->belongsTo(Division::class); }
    public function position() { return $this->belongsTo(Position::class); }
    public function completedByAdmin() { return $this->belongsTo(Admin::class, 'completed_by'); }

    public function tasks()
    {
        return $this->hasMany(OnboardingTask::class)->orderBy('order_index');
    }

    public function calculateProgress(): int
    {
        $total = $this->tasks()->count();
        if ($total === 0) return 0;
        $completed = $this->tasks()->whereIn('status', ['COMPLETED', 'NOT_REQUIRED'])->count();
        return (int) round(($completed / $total) * 100);
    }
}
