<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternshipEvaluation extends Model
{
    use HasFactory;

    protected $fillable = [
        'internship_id',
        'evaluator_id',
        'evaluator_name',
        'evaluator_role',
        'evaluation_type',
        'discipline_score',
        'communication_score',
        'technical_score',
        'initiative_score',
        'teamwork_score',
        'attendance_score',
        'task_completion_score',
        'professionalism_score',
        'average_score',
        'strengths',
        'improvements',
        'final_recommendation',
        'evaluated_at',
    ];

    protected $casts = [
        'evaluated_at' => 'date',
        'average_score' => 'decimal:2',
    ];

    public function internship(): BelongsTo
    {
        return $this->belongsTo(Internship::class, 'internship_id');
    }
}
