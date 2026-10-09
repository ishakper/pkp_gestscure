<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobVacancy extends Model
{
    use HasFactory;

    protected $fillable = [
        'vacancy_code',
        'title',
        'division_id',
        'position_id',
        'building_id',
        'employment_type',
        'experience_level',
        'quota',
        'salary_min',
        'salary_max',
        'description',
        'requirements',
        'status',
        'deadline',
        'created_by',
    ];

    protected $casts = [
        'deadline' => 'date',
        'quota' => 'integer',
        'salary_min' => 'integer',
        'salary_max' => 'integer',
    ];

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class);
    }
}
