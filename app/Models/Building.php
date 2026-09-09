<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class Building extends Model { use HasFactory; protected $fillable=['code','name','description','is_active']; protected $casts=['is_active'=>'boolean']; public function employees(){return $this->hasMany(Employee::class);} public function doors(){return $this->hasMany(Door::class);} public function zones(){return $this->hasMany(Zone::class);} public function divisions(){return $this->hasMany(Division::class);} }
