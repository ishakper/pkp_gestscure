<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Zone extends Model { protected $fillable=['building_id','code','name','is_active']; protected $casts=['is_active'=>'boolean']; public function building(){return $this->belongsTo(Building::class);} public function doors(){return $this->hasMany(Door::class);} }
