<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Candidate extends Model
{
    use HasFactory;

    protected $fillable = [
        'candidate_no',
        'first_name',
        'last_name',
        'email',
        'phone',
        'national_id',
        'address',
        'current_company',
        'current_position',
        'resume_path',
        'portfolio_url',
        'linkedin_url',
        'source',
        'talent_pool_status',
        'converted_employee_id',
        'notes',
    ];

    protected $casts = [
        'talent_pool_status' => 'boolean',
    ];

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function convertedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'converted_employee_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class);
    }
}
