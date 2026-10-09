<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceCorrectionRequest extends Model
{
    use HasFactory;

    protected $table = 'attendance_correction_requests';

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

    public const TYPE_CHECK_IN         = 'CHECK_IN';
    public const TYPE_CHECK_OUT        = 'CHECK_OUT';
    public const TYPE_CHECK_IN_AND_OUT = 'CHECK_IN_AND_OUT';
    public const TYPE_STATUS           = 'STATUS';
    public const TYPE_ATTENDANCE_TYPE  = 'ATTENDANCE_TYPE';

    public const TYPES = [
        self::TYPE_CHECK_IN,
        self::TYPE_CHECK_OUT,
        self::TYPE_CHECK_IN_AND_OUT,
        self::TYPE_STATUS,
        self::TYPE_ATTENDANCE_TYPE,
    ];

    public const SOURCE_EMPLOYEE   = 'EMPLOYEE_REQUEST';
    public const SOURCE_HR_MANUAL  = 'HR_MANUAL';
    public const SOURCE_SUPERVISOR = 'SUPERVISOR_APPROVED';

    protected $fillable = [
        'employee_id',
        'attendance_id',
        'correction_date',
        'request_type',
        'original_check_in',
        'original_check_out',
        'original_status',
        'original_attendance_type',
        'requested_check_in',
        'requested_check_out',
        'requested_status',
        'requested_attendance_type',
        'corrected_check_in',
        'corrected_check_out',
        'corrected_status',
        'corrected_attendance_type',
        'reason',
        'evidence_note',
        'attachment_path',
        'correction_source',
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
        'correction_date'           => 'date',
        'original_check_in'         => 'datetime',
        'original_check_out'        => 'datetime',
        'requested_check_in'        => 'datetime',
        'requested_check_out'       => 'datetime',
        'corrected_check_in'        => 'datetime',
        'corrected_check_out'       => 'datetime',
        'submitted_at'              => 'datetime',
        'approved_at'               => 'datetime',
        'rejected_at'               => 'datetime',
    ];

    public function setCorrectionDateAttribute($value): void
    {
        $this->attributes['correction_date'] = $value ? Carbon::parse($value)->toDateString() : null;
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
