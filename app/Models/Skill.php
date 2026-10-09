<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Skill extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'description'];

    public function jobDescriptions() { return $this->belongsToMany(JobDescription::class, 'skill_requirements')->withPivot('required_level'); }
    public function employeeSkills() { return $this->hasMany(EmployeeSkill::class); }
}
