<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentAcknowledgement extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id',
        'employee_id',
        'internship_id',
        'version',
        'acknowledged_at',
        'ip_address',
        'user_agent',
        'notes',
    ];

    protected $casts = [
        'acknowledged_at' => 'datetime',
        'version' => 'integer',
    ];

    public function document() { return $this->belongsTo(EmployeeDocument::class); }
    public function employee() { return $this->belongsTo(Employee::class); }
    public function internship() { return $this->belongsTo(Internship::class); }
}
