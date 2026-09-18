<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccessLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'log_id',
        'door_id',
        'employee_id',
        'nik',
        'event_type',
        'device_ip',
        'auth_method',
        'verify_method',
        'status',
        'access_status',
        'reason',
        'source',
        'source_format',
        'scanned_at',
        'timestamp',
        'device_serial',
        'major_event',
        'minor_event',
        'correlation_id',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
        'scanned_at' => 'datetime',
    ];

    public function getAuthMethodAttribute()
    {
        return $this->attributes['verify_method'] ?? $this->attributes['auth_method'] ?? 'Fingerprint';
    }

    public function setAuthMethodAttribute($val)
    {
        $this->attributes['auth_method'] = $val;
        $this->attributes['verify_method'] = ucfirst($val);
    }

    public function getVerifyMethodAttribute()
    {
        return $this->attributes['verify_method'] ?? $this->attributes['auth_method'] ?? 'Fingerprint';
    }

    public function setVerifyMethodAttribute($val)
    {
        $this->attributes['verify_method'] = $val;
        $this->attributes['auth_method'] = strtolower($val);
    }

    public function getStatusAttribute()
    {
        return $this->attributes['access_status'] ?? $this->attributes['status'] ?? 'Granted';
    }

    public function setStatusAttribute($val)
    {
        $this->attributes['status'] = $val;
        $this->attributes['access_status'] = $val;
    }

    public function getAccessStatusAttribute()
    {
        return $this->attributes['access_status'] ?? $this->attributes['status'] ?? 'Granted';
    }

    public function setAccessStatusAttribute($val)
    {
        $this->attributes['access_status'] = $val;
        $this->attributes['status'] = $val;
    }

    public function getScannedAtAttribute($value)
    {
        $val = $value ?? ($this->attributes['timestamp'] ?? null);
        return $val ? $this->asDateTime($val) : null;
    }

    public function setScannedAtAttribute($val)
    {
        $this->attributes['scanned_at'] = $val;
        $this->attributes['timestamp'] = $val;
    }

    public function getTimestampAttribute($value)
    {
        $val = $value ?? ($this->attributes['scanned_at'] ?? null);
        return $val ? $this->asDateTime($val) : null;
    }

    public function setTimestampAttribute($val)
    {
        $this->attributes['timestamp'] = $val;
        $this->attributes['scanned_at'] = $val;
    }

    public function door()
    {
        return $this->belongsTo(Door::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function attendanceEvidence()
    {
        return $this->hasOne(AttendanceEvidence::class);
    }
}
