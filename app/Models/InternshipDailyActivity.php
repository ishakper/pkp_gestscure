<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternshipDailyActivity extends Model
{
    use HasFactory;

    protected $fillable = [
        'internship_id',
        'activity_date',
        'title',
        'description',
        'project_task_ref',
        'start_time',
        'end_time',
        'progress_percent',
        'attachment_url',
        'status',
        'mentor_notes',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'activity_date' => 'date',
        'progress_percent' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public function internship(): BelongsTo
    {
        return $this->belongsTo(Internship::class, 'internship_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewed_by');
    }
}
