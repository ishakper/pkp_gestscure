<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contract extends Model
{
    use HasFactory;

    protected $fillable = [
        'contract_number',
        'employee_id',
        'internship_id',
        'contract_type',
        'title',
        'start_date',
        'end_date',
        'effective_date',
        'status',
        'signed_date',
        'signed_by_employee',
        'signed_by_company',
        'renewal_status',
        'notes',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'effective_date' => 'date',
        'signed_date' => 'date',
    ];

    public function employee() { return $this->belongsTo(Employee::class); }
    public function internship() { return $this->belongsTo(Internship::class); }

    public function documents()
    {
        return $this->hasMany(EmployeeDocument::class);
    }
}
