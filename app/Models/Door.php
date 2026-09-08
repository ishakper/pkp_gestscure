<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Door extends Model
{
    use HasFactory;

    protected $fillable = [
        'door_id',
        'name',
        'door_name',
        'location',
        'ip_address',
        'device_ip',
        'gateway',
        'model',
        'device_model',
        'status',
        'connection_status',
        'is_manual_override',
        'last_checked_at',
    ];

    protected $casts = [
        'is_manual_override' => 'boolean',
        'last_checked_at' => 'datetime',
    ];

    public function getNameAttribute()
    {
        return $this->attributes['door_name'] ?? $this->attributes['name'] ?? null;
    }

    public function setNameAttribute($val)
    {
        $this->attributes['name'] = $val;
        $this->attributes['door_name'] = $val;
    }

    public function getDoorNameAttribute()
    {
        return $this->attributes['door_name'] ?? $this->attributes['name'] ?? null;
    }

    public function setDoorNameAttribute($val)
    {
        $this->attributes['door_name'] = $val;
        $this->attributes['name'] = $val;
    }

    public function getIpAddressAttribute()
    {
        return $this->attributes['device_ip'] ?? $this->attributes['ip_address'] ?? null;
    }

    public function setIpAddressAttribute($val)
    {
        $this->attributes['ip_address'] = $val;
        $this->attributes['device_ip'] = $val;
    }

    public function getDeviceIpAttribute()
    {
        return $this->attributes['device_ip'] ?? $this->attributes['ip_address'] ?? null;
    }

    public function setDeviceIpAttribute($val)
    {
        $this->attributes['device_ip'] = $val;
        $this->attributes['ip_address'] = $val;
    }

    public function getModelAttribute()
    {
        return $this->attributes['device_model'] ?? $this->attributes['model'] ?? 'DS-K1T804AMF';
    }

    public function setModelAttribute($val)
    {
        $this->attributes['model'] = $val;
        $this->attributes['device_model'] = $val;
    }

    public function getDeviceModelAttribute()
    {
        return $this->attributes['device_model'] ?? $this->attributes['model'] ?? 'DS-K1T804AMF';
    }

    public function setDeviceModelAttribute($val)
    {
        $this->attributes['device_model'] = $val;
        $this->attributes['model'] = $val;
    }

    public function getStatusAttribute()
    {
        return $this->attributes['connection_status'] ?? $this->attributes['status'] ?? 'online';
    }

    public function setStatusAttribute($val)
    {
        $this->attributes['status'] = $val;
        $this->attributes['connection_status'] = $val;
    }

    public function getConnectionStatusAttribute()
    {
        return $this->attributes['connection_status'] ?? $this->attributes['status'] ?? 'online';
    }

    public function setConnectionStatusAttribute($val)
    {
        $this->attributes['connection_status'] = $val;
        $this->attributes['status'] = $val;
    }

    public function doorAssignments()
    {
        return $this->hasMany(DoorAssignment::class);
    }

    public function employees()
    {
        return $this->belongsToMany(Employee::class, 'door_assignments')
                    ->withPivot('sync_status', 'sync_attempts', 'last_synced_at', 'last_sync_error')
                    ->withTimestamps();
    }

    public function accessLogs()
    {
        return $this->hasMany(AccessLog::class);
    }
}
