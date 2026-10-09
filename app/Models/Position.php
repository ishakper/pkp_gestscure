<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Position extends Model { protected $fillable=['division_id','code','name','is_active']; protected $casts=['is_active'=>'boolean']; public function division(){return $this->belongsTo(Division::class);} public function employees(){return $this->hasMany(Employee::class);} }
