<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FieldAttendanceEvidence extends Model
{
    use HasFactory;

    protected $table = 'field_attendance_evidences';

    protected $fillable = [
        'employee_id',
        'field_assignment_id',
        'field_location_id',
        'attendance_id',
        'attendance_date',
        'type', // CHECK_IN, CHECK_OUT
        'latitude',
        'longitude',
        'accuracy_meters',
        'captured_at',
        'server_received_at',
        'distance_meters',
        'geofence_result', // VALID, OUTSIDE_GEOFENCE, LOW_ACCURACY, UNAVAILABLE, ANOMALY
        'photo_path',
        'photo_mime',
        'photo_size_bytes',
        'anomaly_flags',
        'notes',
        'is_override',
        'override_reason',
        'override_by',
        'override_at',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'latitude' => 'float',
        'longitude' => 'float',
        'accuracy_meters' => 'float',
        'distance_meters' => 'float',
        'captured_at' => 'datetime',
        'server_received_at' => 'datetime',
        'anomaly_flags' => 'array',
        'is_override' => 'boolean',
        'override_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function fieldAssignment()
    {
        return $this->belongsTo(FieldAssignment::class);
    }

    public function fieldLocation()
    {
        return $this->belongsTo(FieldLocation::class);
    }

    public function attendance()
    {
        return $this->belongsTo(Attendance::class);
    }

    public function overrideAdmin()
    {
        return $this->belongsTo(Admin::class, 'override_by');
    }

    public function isVerified(): bool
    {
        return $this->geofence_result === 'VALID' || $this->is_override;
    }
}
