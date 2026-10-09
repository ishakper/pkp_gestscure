<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeSkill extends Model
{
    protected $fillable = ['employee_id', 'skill_id', 'declared_level', 'verified_level', 'verified_by', 'declared_at', 'verified_at', 'verification_notes'];
    protected $casts = ['declared_level' => 'integer', 'verified_level' => 'integer', 'declared_at' => 'datetime', 'verified_at' => 'datetime'];
    public function employee() { return $this->belongsTo(Employee::class); }
    public function skill() { return $this->belongsTo(Skill::class); }
    public function verifier() { return $this->belongsTo(Admin::class, 'verified_by'); }
}
