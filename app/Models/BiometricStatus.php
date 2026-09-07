<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BiometricStatus extends Model
{
    use HasFactory;

    protected $table = 'biometric_statuses';

    protected $fillable = [
        'employee_id',
        'has_fingerprint',
        'fingerprint_enrolled',
        'card_enrolled',
        'biometric_template',
    ];

    protected $casts = [
        'has_fingerprint' => 'boolean',
        'fingerprint_enrolled' => 'boolean',
        'card_enrolled' => 'boolean',
    ];

    public function getHasFingerprintAttribute()
    {
        return (bool) ($this->attributes['has_fingerprint'] ?? $this->attributes['fingerprint_enrolled'] ?? false);
    }

    public function setHasFingerprintAttribute($val)
    {
        $this->attributes['has_fingerprint'] = (bool) $val;
        $this->attributes['fingerprint_enrolled'] = (bool) $val;
    }

    public function getFingerprintEnrolledAttribute()
    {
        return (bool) ($this->attributes['fingerprint_enrolled'] ?? $this->attributes['has_fingerprint'] ?? false);
    }

    public function setFingerprintEnrolledAttribute($val)
    {
        $this->attributes['fingerprint_enrolled'] = (bool) $val;
        $this->attributes['has_fingerprint'] = (bool) $val;
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
