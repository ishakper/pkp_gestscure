<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'employee_id',
        'hikvision_employee_no',
        'nik',
        'name',
        'email',
        'phone',
        'photo_path',
        'card_no',
        'department',
        'role',
        'role_jabatan',
        'building_id',
        'division_id',
        'position_id',
        'employment_type',
        'employment_status',
        'hire_date',
        'supervisor_id',
    ];

    protected $casts = ['hire_date' => 'date'];

    public function getRoleAttribute()
    {
        return $this->attributes['role'] ?? $this->attributes['role_jabatan'] ?? 'Staff';
    }

    public function setRoleAttribute($val)
    {
        $this->attributes['role'] = $val;
        $this->attributes['role_jabatan'] = $val;
    }

    public function getRoleJabatanAttribute()
    {
        return $this->attributes['role_jabatan'] ?? $this->attributes['role'] ?? 'Staff';
    }

    public function setRoleJabatanAttribute($val)
    {
        $this->attributes['role_jabatan'] = $val;
        $this->attributes['role'] = $val;
    }

    public function supervisor() { return $this->belongsTo(self::class, 'supervisor_id'); }
    public function directReports() { return $this->hasMany(self::class, 'supervisor_id'); }

    public function building() { return $this->belongsTo(Building::class); }
    public function division() { return $this->belongsTo(Division::class); }
    public function position() { return $this->belongsTo(Position::class); }

    public function biometricStatus()
    {
        return $this->hasOne(BiometricStatus::class);
    }

    public function doorAssignments()
    {
        return $this->hasMany(DoorAssignment::class);
    }

    public function doors()
    {
        return $this->belongsToMany(Door::class, 'door_assignments')
                    ->withPivot('sync_status', 'sync_attempts', 'last_synced_at', 'last_sync_error')
                    ->withTimestamps();
    }

    public function accessLogs()
    {
        return $this->hasMany(AccessLog::class);
    }

    public function onboardingCases()
    {
        return $this->hasMany(OnboardingCase::class);
    }

    public function contracts()
    {
        return $this->hasMany(Contract::class);
    }

    public function documents()
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    public function documentAcknowledgements()
    {
        return $this->hasMany(DocumentAcknowledgement::class);
    }

    public function accessRequests()
    {
        return $this->hasMany(AccessRequest::class);
    }

    public function credentials()
    {
        return $this->hasMany(CredentialRecord::class);
    }

    public function emoneyCards()
    {
        return $this->hasMany(EmoneyCard::class);
    }
}
