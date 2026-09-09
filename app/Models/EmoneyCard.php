<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmoneyCard extends Model
{
    use HasFactory;

    protected $fillable = [
        'card_uuid',
        'employee_id',
        'internship_id',
        'provider',
        'masked_card_number',
        'card_hash',
        'status',
        'issued_at',
        'expires_at',
        'assigned_by',
        'assigned_at',
        'returned_at',
        'notes',
    ];

    protected $casts = [
        'issued_at' => 'date',
        'expires_at' => 'date',
        'assigned_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    protected $hidden = [
        'card_hash',
    ];

    public function employee() { return $this->belongsTo(Employee::class); }
    public function internship() { return $this->belongsTo(Internship::class); }
    public function assignedByAdmin() { return $this->belongsTo(Admin::class, 'assigned_by'); }
}
