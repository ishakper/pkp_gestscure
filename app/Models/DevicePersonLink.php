<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DevicePersonLink extends Model
{
    public const LINKED = 'LINKED';
    public const REVIEW = 'REVIEW';
    public const IGNORED = 'IGNORED';

    protected $fillable = ['door_id', 'device_employee_no', 'employee_id', 'decision', 'decided_by', 'note'];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function decider()
    {
        return $this->belongsTo(Admin::class, 'decided_by');
    }
}
