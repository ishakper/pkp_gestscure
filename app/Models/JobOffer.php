<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobOffer extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_application_id',
        'offered_salary',
        'start_date',
        'expiry_date',
        'status',
        'terms',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'offered_salary' => 'integer',
        'start_date' => 'date',
        'expiry_date' => 'date',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }
}
