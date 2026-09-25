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
        'building_id',
        'floor_id',
        'zone_id',
        'ip_address',
        'device_ip',
        'gateway',
        'model',
        'device_model',
        'serial_number',
        'firmware_version',
        'isapi_username',
        'isapi_password',
        'status',
        'connection_status',
        'health_status',
        'is_manual_override',
        'last_checked_at',
        'connection_mode',
        'connection_scheme',
        'device_port',
        'connect_timeout',
        'read_timeout',
        'verify_tls',
        'last_connection_test_at',
        'last_connection_status',
    ];

    protected $hidden = [
        'isapi_password',
    ];

    protected $casts = [
        'is_manual_override' => 'boolean',
        'last_checked_at' => 'datetime',
        'isapi_password' => 'encrypted',
        'device_port' => 'integer',
        'connect_timeout' => 'integer',
        'read_timeout' => 'integer',
        'verify_tls' => 'boolean',
        'last_connection_test_at' => 'datetime',
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

    // Manual connection config accessors - enforce valid ranges
    public function getDevicePortAttribute($val)
    {
        $port = (int) ($val ?? 8200);
        return max(1, min(65535, $port));
    }

    public function getConnectTimeoutAttribute($val)
    {
        $timeout = (int) ($val ?? 10);
        return max(1, min(30, $timeout));
    }

    public function getReadTimeoutAttribute($val)
    {
        $timeout = (int) ($val ?? 10);
        return max(1, min(30, $timeout));
    }

    public function remoteUnlockAllowed(): bool
    {
        $health = strtolower((string) ($this->attributes['health_status'] ?? $this->attributes['connection_status'] ?? $this->attributes['status'] ?? 'offline'));
        return $health === 'online' && ! $this->is_manual_override && filled($this->device_ip ?? $this->ip_address);
    }

    public function setConnectionStatusAttribute($val)
    {
        $this->attributes['connection_status'] = $val;
        $this->attributes['status'] = $val;
    }

    /**
     * Test manual connection configuration (read-only)
     * Uses manually specified connection parameters instead of global config
     */
    public function testConnection(\App\Services\HikvisionIsapiService $service): array
    {
        return $service->testManualDeviceConnection(
            $this->device_ip,
            $this->device_port,
            $this->connection_scheme,
            $this->connect_timeout,
            $this->isapi_username,
            $this->isapi_password,
            $this->verify_tls
        );
    }

    public function building() { return $this->belongsTo(Building::class); }
    public function zone() { return $this->belongsTo(Zone::class); }

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
