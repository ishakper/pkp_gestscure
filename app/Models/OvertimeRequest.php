<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OvertimeRequest extends Model
{
    use HasFactory;

    protected $table = 'overtime_requests';

    public const STATUS_DRAFT     = 'DRAFT';
    public const STATUS_SUBMITTED = 'SUBMITTED';
    public const STATUS_APPROVED  = 'APPROVED';
    public const STATUS_REJECTED  = 'REJECTED';
    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'employee_id',
        'attendance_id',
        'overtime_date',
        'requested_start',
        'requested_end',
        'requested_minutes',
        'approved_minutes',
        'reason',
        'project_task_reference',
        'attachment_path',
        'status',
        'submitted_at',
        'approved_at',
        'approved_by',
        'rejected_at',
        'rejected_by',
        'rejection_reason',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'overtime_date'     => 'date',
        'requested_minutes' => 'integer',
        'approved_minutes'  => 'integer',
        'submitted_at'      => 'datetime',
        'approved_at'       => 'datetime',
        'rejected_at'       => 'datetime',
    ];

    public function setOvertimeDateAttribute($value): void
    {
        $this->attributes['overtime_date'] = $value ? Carbon::parse($value)->toDateString() : null;
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function attendance()
    {
        return $this->belongsTo(Attendance::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(Admin::class, 'approved_by');
    }

    public function rejectedBy()
    {
        return $this->belongsTo(Admin::class, 'rejected_by');
    }

    public function createdBy()
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(Admin::class, 'updated_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
