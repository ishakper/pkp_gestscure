<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SkillRequirement extends Model
{
    protected $fillable = ['job_description_id', 'skill_id', 'required_level'];
    protected $casts = ['required_level' => 'integer'];
    public function jobDescription() { return $this->belongsTo(JobDescription::class); }
    public function skill() { return $this->belongsTo(Skill::class); }
}
