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
        'nik',
        'name',
        'card_no',
        'department',
        'role',
        'role_jabatan',
    ];

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
}
