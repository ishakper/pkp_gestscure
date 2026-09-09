<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Internship extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'intern_id',
        'employee_id',
        'candidate_id',
        'job_application_id',
        'institution',
        'major',
        'education_level',
        'semester',
        'start_date',
        'end_date',
        'division_id',
        'position_title',
        'mentor_id',
        'supervisor_id',
        'campus_supervisor_name',
        'campus_supervisor_contact',
        'project_assignment',
        'status',
        'completed_at',
        'completion_notes',
        'certificate_no',
        'access_revocation_marked',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'completed_at' => 'datetime',
        'access_revocation_marked' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class, 'candidate_id');
    }

    public function jobApplication(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class, 'division_id');
    }

    public function mentor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'mentor_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }

    public function dailyActivities(): HasMany
    {
        return $this->hasMany(InternshipDailyActivity::class, 'internship_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(InternshipReport::class, 'internship_id');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(InternshipEvaluation::class, 'internship_id');
    }

    public function onboardingCases(): HasMany
    {
        return $this->hasMany(OnboardingCase::class, 'internship_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'internship_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class, 'internship_id');
    }

    public function accessRequests(): HasMany
    {
        return $this->hasMany(AccessRequest::class, 'internship_id');
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(CredentialRecord::class, 'internship_id');
    }

    public function emoneyCards(): HasMany
    {
        return $this->hasMany(EmoneyCard::class, 'internship_id');
    }

    public function assetAssignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class, 'internship_id');
    }

    public function activeAssetAssignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class, 'internship_id')->where('status', 'ACTIVE');
    }
}
