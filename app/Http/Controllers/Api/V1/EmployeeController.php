<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignDoorAccessRequest;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Jobs\SyncDoorAccessJob;
use App\Models\ActivityLog;
use App\Models\BiometricStatus;
use App\Models\Door;
use App\Models\DoorAssignment;
use App\Models\Employee;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    private const EMPLOYEE_FIELDS = ['employee_id','hikvision_employee_no','nik','name','email','phone','photo_path','card_no','department','role','role_jabatan','building_id','division_id','position_id','employment_type','employment_status','hire_date','supervisor_id'];

    public function index(Request $request)
    {
        $admin = $request->user();
        $query = Employee::with(['biometricStatus', 'doors', 'building', 'division', 'position', 'supervisor']);
        if ($request->filled('search')) { $search = $request->input('search'); $query->where(fn ($q) => $q->where('name','like',"%{$search}%")->orWhere('nik','like',"%{$search}%")->orWhere('employee_id','like',"%{$search}%")); }
        foreach (['building_id','division_id','position_id','employment_status'] as $filter) { if ($request->filled($filter)) $query->where($filter, $request->input($filter)); }
        if ($request->filled('door_id')) { $doorId=$request->input('door_id'); $query->whereHas('doors', fn($q) => $q->where('doors.door_id',$doorId)->orWhere('doors.id',$doorId)); }
        if ($admin && $admin->isBuildingAdmin() && $admin->assigned_building) {
            $query->where(fn($q) => $q->whereHas('building', fn($b) => $b->where('name',$admin->assigned_building))->orWhereHas('doors', fn($d) => $d->where('location',$admin->assigned_building)));
        }
        $employees=$query->paginate(min(max((int)$request->get('per_page',10),1),100));
        return response()->json(['status'=>'success','pagination'=>['current_page'=>$employees->currentPage(),'per_page'=>$employees->perPage(),'total_records'=>$employees->total(),'total_pages'=>$employees->lastPage()],'data'=>EmployeeResource::collection($employees)]);
    }

    public function store(StoreEmployeeRequest $request)
    {
        $this->authorize('create', Employee::class);
        $employeeId=$request->input('employee_id') ?: 'USR-'.(1000+(Employee::withTrashed()->max('id') ?? 0)+1);
        $values=$request->safe()->only(self::EMPLOYEE_FIELDS);
        $values['employee_id']=$employeeId;
        $values['hikvision_employee_no']=$values['hikvision_employee_no'] ?? $employeeId;
        $values['role']=$values['role'] ?? $values['role_jabatan'] ?? 'Staff';
        $values['role_jabatan']=$values['role_jabatan'] ?? $values['role'];
        $values['employment_status']=$values['employment_status'] ?? 'ACTIVE';
        $employee=Employee::create($values);
        $hasFp=(bool)$request->input('fingerprint_enrolled',false); $cardEnrolled=!empty($employee->card_no)||(bool)$request->input('card_enrolled',false);
        // The device retains biometric templates. This system stores enrollment state only.
        BiometricStatus::create(['employee_id'=>$employee->id,'has_fingerprint'=>$hasFp,'fingerprint_enrolled'=>$hasFp,'card_enrolled'=>$cardEnrolled,'biometric_template'=>null]);
        $this->assignInitialDoors($employee, $request->input('door_ids', []));
        $this->audit($request, 'create_employee', $employee, 'Created employee master record');
        return response()->json(['status'=>'success','message'=>'Karyawan berhasil ditambahkan','data'=>new EmployeeResource($employee->load(['biometricStatus','doors','building','division','position']))],201);
    }

    protected function findEmployeeByIdentifier($id): Employee { return Employee::where('id',$id)->orWhere('employee_id',$id)->firstOrFail(); }
    public function profile360(Request $request, $id)
    {
        $employee = $this->findEmployeeByIdentifier($id)->load(['biometricStatus','doors','building','division','position','supervisor','directReports','accessLogs','credentials.deviceSyncs.door','accessRequests.accessProfile','emoneyCards']);
        $this->authorize('view', $employee);

        $actor = $request->user();
        $isTechOnly = in_array(strtolower((string)$actor?->role), ['developer', 'devops', 'infra_admin'], true) && !$actor?->isSuperAdmin();

        $credentialsData = [];
        if (!$isTechOnly) {
            $credentialsData = $employee->credentials->map(fn($c) => [
                'id' => $c->id,
                'credential_number' => $c->credential_number,
                'credential_type' => $c->credential_type,
                'masked_identifier' => $c->masked_identifier,
                'biometric_status' => $c->biometric_status,
                'status' => $c->status,
                'issued_at' => $c->issued_at?->toIso8601String(),
                'sync_count' => $c->deviceSyncs->count(),
            ]);
        }

        $emoneySummary = [];
        if (!$isTechOnly && ($actor?->isSuperAdmin() || strtolower((string)$actor?->role) === 'hrd' || $actor?->id === $employee->id)) {
            $emoneySummary = $employee->emoneyCards->map(fn($e) => [
                'id' => $e->id,
                'card_uuid' => $e->card_uuid,
                'provider' => $e->provider,
                'masked_card_number' => $e->masked_card_number,
                'status' => $e->status,
            ]);
        }

        return response()->json(['status'=>'success','data'=>[
            'employee'=>new EmployeeResource($employee),
            'overview'=>['employment_status'=>$employee->employment_status,'employment_type'=>$employee->employment_type,'hire_date'=>$employee->hire_date?->toDateString()],
            'organization'=>['building'=>$employee->building?->only(['id','code','name']),'division'=>$employee->division?->only(['id','code','name']),'position'=>$employee->position?->only(['id','code','name']),'supervisor'=>$employee->supervisor?->only(['id','employee_id','name'])],
            'direct_reports'=>$employee->directReports->map(fn($report)=>$report->only(['id','employee_id','name','employment_status'])),
            'access'=>[
                'assigned_doors'=>$employee->doors->map(fn($door)=>['door_id'=>$door->door_id,'name'=>$door->name]),
                'access_requests'=>$employee->accessRequests->map(fn($req)=>['request_number'=>$req->request_number,'profile'=>$req->accessProfile?->name,'status'=>$req->status,'valid_from'=>$req->valid_from?->toDateString(),'valid_until'=>$req->valid_until?->toDateString()]),
            ],
            'credentials'=>$credentialsData,
            'emoney_summary'=>$emoneySummary,
            'audit_summary'=>['access_log_count'=>$employee->accessLogs->count()]
        ]]);
    }
    public function show($id) { $employee=$this->findEmployeeByIdentifier($id)->load(['biometricStatus','doors','building','division','position']); $this->authorize('view',$employee); return response()->json(['status'=>'success','data'=>new EmployeeResource($employee)]); }

    public function update(UpdateEmployeeRequest $request, $id)
    {
        $employee=$this->findEmployeeByIdentifier($id)->load('biometricStatus'); $this->authorize('update',$employee);
        $values=$request->safe()->only(self::EMPLOYEE_FIELDS); $employee->update($values);
        if ($employee->biometricStatus) { $employee->biometricStatus->update(['has_fingerprint'=>$request->has('fingerprint_enrolled')?(bool)$request->fingerprint_enrolled:$employee->biometricStatus->has_fingerprint,'fingerprint_enrolled'=>$request->has('fingerprint_enrolled')?(bool)$request->fingerprint_enrolled:$employee->biometricStatus->fingerprint_enrolled,'card_enrolled'=>$request->has('card_enrolled')?(bool)$request->card_enrolled:$employee->biometricStatus->card_enrolled]); }
        if (in_array(strtoupper((string)$employee->employment_status), ['INACTIVE', 'RESIGNED', 'TERMINATED'], true)) {
            app(\App\Services\AccessProvisioningService::class)->revokeEmployeeAccess($employee, 'Status karyawan diubah ke ' . $employee->employment_status, $request->user());
        }
        $this->audit($request,'update_employee',$employee,'Updated employee master record');
        return response()->json(['status'=>'success','message'=>'Data karyawan berhasil diperbarui','data'=>new EmployeeResource($employee->load(['biometricStatus','doors','building','division','position']))]);
    }

    public function destroy(Request $request, $id) { $employee=$this->findEmployeeByIdentifier($id); $this->authorize('delete',$employee); $employee->update(['employment_status'=>'INACTIVE']); app(\App\Services\AccessProvisioningService::class)->revokeEmployeeAccess($employee, 'Karyawan dinonaktifkan', $request->user()); $this->audit($request,'deactivate_employee',$employee,'Marked employee inactive; historical access logs preserved'); return response()->json(['status'=>'success','message'=>'Karyawan dinonaktifkan; riwayat akses tetap tersimpan.']); }

    public function assignDoorAccess(AssignDoorAccessRequest $request, $id) { $employee=$this->findEmployeeByIdentifier($id); $door=Door::where('door_id',$request->door_id)->orWhere('id',$request->door_id)->firstOrFail(); $this->authorize('assignDoor',[$employee,$door]); $assignment=DoorAssignment::updateOrCreate(['employee_id'=>$employee->id,'door_id'=>$door->id],['sync_status'=>'pending','sync_attempts'=>0]); SyncDoorAccessJob::dispatch($assignment->id); $this->audit($request,'assign_door_access',$employee,"Assigned door {$door->door_id}"); return response()->json(['status'=>'success','message'=>"Hak akses {$door->door_name} berhasil diberikan. Sinkronisasi ke perangkat sedang diproses.",'data'=>['employee_id'=>$employee->employee_id,'door_id'=>$door->door_id,'sync_status'=>$assignment->sync_status]]); }
    public function revokeDoorAccess(Request $request,$id,$door_id) { $employee=$this->findEmployeeByIdentifier($id); $door=Door::where('door_id',$door_id)->orWhere('id',$door_id)->firstOrFail(); $this->authorize('assignDoor',[$employee,$door]); DoorAssignment::where('employee_id',$employee->id)->where('door_id',$door->id)->delete(); $this->audit($request,'revoke_door_access',$employee,"Revoked door {$door->door_id}"); return response()->json(['status'=>'success','message'=>"Hak akses {$door->door_name} berhasil dicabut."]); }
    private function assignInitialDoors(Employee $employee,array $doorIds): void { foreach (Door::whereIn('door_id',$doorIds)->orWhereIn('id',$doorIds)->get() as $door) { $assignment=DoorAssignment::firstOrCreate(['employee_id'=>$employee->id,'door_id'=>$door->id],['sync_status'=>'pending','sync_attempts'=>0]); SyncDoorAccessJob::dispatch($assignment->id); } }
    private function audit(Request $request,string $action,Employee $employee,string $description): void { ActivityLog::create(['admin_id'=>$request->user()?->id,'action'=>$action,'subject_type'=>'Employee','subject_id'=>$employee->id,'description'=>$description.' ['.$employee->employee_id.']','timestamp'=>now()]); }
}
