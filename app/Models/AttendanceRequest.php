<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AttendanceRequest extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPE_WFH        = 'WFH';
    public const TYPE_LEAVE      = 'LEAVE';
    public const TYPE_PERMISSION = 'PERMISSION';
    public const TYPE_SICK       = 'SICK';

    public const TYPES = [
        self::TYPE_WFH,
        self::TYPE_LEAVE,
        self::TYPE_PERMISSION,
        self::TYPE_SICK,
    ];

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
        'request_type',
        'status',
        'category',
        'start_date',
        'end_date',
        'start_time',
        'end_time',
        'reason',
        'attachment_path',
        'attachment_mime',
        'attachment_size_bytes',
        'attachment_original_name',
        'metadata',
        'submitted_at',
        'approved_at',
        'approved_by',
        'rejected_at',
        'rejected_by',
        'rejection_reason',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'start_date'            => 'date',
        'end_date'              => 'date',
        'submitted_at'          => 'datetime',
        'approved_at'           => 'datetime',
        'rejected_at'           => 'datetime',
        'cancelled_at'          => 'datetime',
        'metadata'              => 'array',
        'attachment_size_bytes' => 'integer',
    ];

    public function setStartDateAttribute($value): void
    {
        $this->attributes['start_date'] = $value ? Carbon::parse($value)->toDateString() : null;
    }

    public function setEndDateAttribute($value): void
    {
        $this->attributes['end_date'] = $value ? Carbon::parse($value)->toDateString() : null;
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver()
    {
        return $this->belongsTo(Admin::class, 'approved_by');
    }

    public function rejecter()
    {
        return $this->belongsTo(Admin::class, 'rejected_by');
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function coversDate(string|Carbon $date): bool
    {
        $d = is_string($date) ? Carbon::parse($date)->toDateString() : $date->toDateString();
        $start = Carbon::parse($this->start_date)->toDateString();
        $end = Carbon::parse($this->end_date)->toDateString();

        return $d >= $start && $d <= $end;
    }
}
