<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class JobApplication extends Model
{
    use HasFactory;

    public const STAGES = [
        'APPLIED' => 'Berkas Masuk',
        'SCREENING' => 'Screening CV',
        'HR_INTERVIEW' => 'Interview HR',
        'TECHNICAL_TEST' => 'Tes Teknis / Assessment',
        'USER_INTERVIEW' => 'Interview User / Supervisor',
        'MANAGEMENT_REVIEW' => 'Review Manajemen',
        'OFFER' => 'Offering & Negosiasi',
        'ACCEPTED' => 'Diterima / Hired',
        'REJECTED' => 'Ditolak',
        'TALENT_POOL' => 'Talent Pool',
        'WITHDRAWN' => 'Mengundurkan Diri',
    ];

    protected $fillable = [
        'application_no',
        'job_vacancy_id',
        'candidate_id',
        'current_stage',
        'status',
        'applied_at',
        'expected_salary',
        'rating',
        'rejection_reason',
        'notes',
    ];

    protected $casts = [
        'applied_at' => 'datetime',
        'expected_salary' => 'integer',
        'rating' => 'integer',
    ];

    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(JobVacancy::class, 'job_vacancy_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function interviews(): HasMany
    {
        return $this->hasMany(Interview::class);
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(JobOffer::class);
    }

    public function latestOffer(): HasOne
    {
        return $this->hasOne(JobOffer::class)->latestOfMany();
    }
}
