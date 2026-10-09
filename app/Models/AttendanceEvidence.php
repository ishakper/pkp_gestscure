<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceEvidence extends Model
{
    use HasFactory;

    protected $table = 'attendance_evidences';

    protected $fillable = [
        'access_log_id',
        'attendance_id',
        'employee_id',
        'door_id',
        'event_timestamp',
        'direction',
        'credential_type',
        'hardware_serial',
        'status',
    ];

    protected $casts = [
        'event_timestamp' => 'datetime',
    ];

    public function accessLog()
    {
        return $this->belongsTo(AccessLog::class);
    }

    public function attendance()
    {
        return $this->belongsTo(Attendance::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function door()
    {
        return $this->belongsTo(Door::class);
    }
}
