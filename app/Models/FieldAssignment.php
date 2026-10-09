<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FieldAssignment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'employee_id',
        'field_location_id',
        'start_date',
        'end_date',
        'supervisor_id',
        'status',
        'approved_by',
        'approved_at',
        'notes',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'approved_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function fieldLocation()
    {
        return $this->belongsTo(FieldLocation::class);
    }

    public function supervisor()
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }

    public function approver()
    {
        return $this->belongsTo(Admin::class, 'approved_by');
    }

    public function evidences()
    {
        return $this->hasMany(FieldAttendanceEvidence::class);
    }

    public function isActiveOnDate(string $date): bool
    {
        if ($this->status !== 'ACTIVE') {
            return false;
        }

        $formatted = \Carbon\Carbon::parse($date)->toDateString();
        return $formatted >= $this->start_date->toDateString() && $formatted <= $this->end_date->toDateString();
    }
}
