<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OnboardingTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'onboarding_case_id',
        'task_key',
        'title',
        'category',
        'status',
        'is_required',
        'order_index',
        'due_date',
        'assigned_to',
        'completed_at',
        'completed_by',
        'blocker_reason',
        'notes',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'due_date' => 'date',
        'completed_at' => 'datetime',
    ];

    public function onboardingCase()
    {
        return $this->belongsTo(OnboardingCase::class);
    }

    public function completedByAdmin()
    {
        return $this->belongsTo(Admin::class, 'completed_by');
    }
}
