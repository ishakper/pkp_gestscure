<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\{Building,Division,Position,Zone};
use Illuminate\Http\Request;
class OrganizationController extends Controller {
 public function lookup(Request $request) {
   $admin=$request->user(); abort_unless($admin, 401);
   $buildings=Building::where('is_active',true);
   if ($admin->isBuildingAdmin() && $admin->assigned_building) $buildings->where('name',$admin->assigned_building);
   $buildingIds=(clone $buildings)->pluck('id');
   $divisions=Division::where('is_active',true)->when($admin->isBuildingAdmin(), fn($q)=>$q->whereIn('building_id',$buildingIds));
   $divisionIds=(clone $divisions)->pluck('id');
   return response()->json(['status'=>'success','data'=>['buildings'=>$buildings->orderBy('name')->get(['id','code','name']),'divisions'=>$divisions->orderBy('name')->get(['id','building_id','code','name']),'positions'=>Position::where('is_active',true)->when($admin->isBuildingAdmin(),fn($q)=>$q->whereIn('division_id',$divisionIds))->orderBy('name')->get(['id','division_id','code','name']),'zones'=>Zone::where('is_active',true)->when($admin->isBuildingAdmin(),fn($q)=>$q->whereIn('building_id',$buildingIds))->orderBy('name')->get(['id','building_id','code','name'])]]);
 }
 public function index(Request $request,string $type) { $this->ensureSuperAdmin($request); return response()->json(['status'=>'success','data'=>$this->model($type)->orderBy('name')->get()]); }
 public function store(Request $request,string $type) { $this->ensureSuperAdmin($request); $model=$this->model($type); $data=$request->validate($this->rules($type)); return response()->json(['status'=>'success','data'=>$model->create($data)],201); }
 public function update(Request $request,string $type,int $id) { $this->ensureSuperAdmin($request); $item=$this->model($type)->findOrFail($id); $item->update($request->validate($this->rules($type,$id))); return response()->json(['status'=>'success','data'=>$item]); }
 private function ensureSuperAdmin(Request $request): void { abort_unless($request->user()?->isSuperAdmin(),403); }
 private function model(string $type) { return match($type) {'buildings'=>new Building,'divisions'=>new Division,'positions'=>new Position,'zones'=>new Zone,default=>abort(404)}; }
 private function rules(string $type,?int $id=null): array { $unique=fn($column)=>'required|string|max:100|unique:'.$type.','.$column.($id?','.$id:''); $base=['code'=>$unique('code'),'name'=>'required|string|max:255','is_active'=>'sometimes|boolean']; return match($type) {'buildings'=>$base+['description'=>'nullable|string|max:1000'],'divisions'=>$base+['building_id'=>'nullable|exists:buildings,id'],'positions'=>$base+['division_id'=>'nullable|exists:divisions,id'],'zones'=>$base+['building_id'=>'required|exists:buildings,id']}; }
}

