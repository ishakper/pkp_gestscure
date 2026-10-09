<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JobDescription extends Model
{
    use HasFactory;

    protected $fillable = ['position_id', 'title', 'version', 'status', 'effective_from', 'effective_until', 'content', 'created_by'];

    protected $casts = ['effective_from' => 'date', 'effective_until' => 'date', 'version' => 'integer'];

    public function position() { return $this->belongsTo(Position::class); }
    public function creator() { return $this->belongsTo(Admin::class, 'created_by'); }
    public function skillRequirements() { return $this->hasMany(SkillRequirement::class); }
    public function skills() { return $this->belongsToMany(Skill::class, 'skill_requirements')->withPivot('required_level'); }
}
