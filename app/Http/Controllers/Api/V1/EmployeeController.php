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
use OpenApi\Attributes as OA;

class EmployeeController extends Controller
{
    private const EMPLOYEE_FIELDS = ['employee_id','hikvision_employee_no','nik','name','email','phone','photo_path','card_no','department','role','role_jabatan','building_id','division_id','position_id','employment_type','employment_status','hire_date','supervisor_id'];

    #[OA\Get(
        path: '/user-management/employees',
        summary: 'Daftar Karyawan / Pengguna',
        description: 'Mendapatkan daftar karyawan dengan pencarian, filter gedung/divisi/posisi/pintu, dan paginasi.',
        tags: ['Employee'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', description: 'Cari nama, NIK, atau ID Karyawan', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'building_id', in: 'query', description: 'Filter ID Gedung', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'division_id', in: 'query', description: 'Filter ID Divisi', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'position_id', in: 'query', description: 'Filter ID Posisi', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'door_id', in: 'query', description: 'Filter Hak Akses Pintu', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', description: 'Halaman paginasi', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Jumlah data per halaman', required: false, schema: new OA\Schema(type: 'integer', default: 10))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Berhasil mengambil daftar karyawan',
                content: new OA\JsonContent(
                    example: [
                        'status' => 'success',
                        'pagination' => ['current_page' => 1, 'per_page' => 10, 'total_records' => 1, 'total_pages' => 1],
                        'data' => [
                            [
                                'id' => 1,
                                'employee_id' => 'USR-1001',
                                'name' => 'Budi Santoso',
                                'email' => 'budi.santoso@example.com',
                                'phone' => '08123456789',
                                'employment_status' => 'ACTIVE'
                            ]
                        ]
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated')
        ]
    )]
    public function index(Request $request)
    {
        $admin = $request->user();
        $baseQuery = Employee::with(['biometricStatus', 'doors', 'building', 'division', 'position', 'supervisor']);
        
        // Apply authorization scope
        if ($admin && $admin->isBuildingAdmin() && $admin->assigned_building) {
            $baseQuery->where(fn($q) => $q->whereHas('building', fn($b) => $b->where('name',$admin->assigned_building))->orWhereHas('doors', fn($d) => $d->where('location',$admin->assigned_building)));
        }
        
        // Get total_all before applying search/filters
        $totalAll = (clone $baseQuery)->count();
        
        // Apply search
        if ($request->filled('search')) { 
            $search = $request->input('search'); 
            $baseQuery->where(fn ($q) => $q->where('name','like',"%{$search}%")->orWhere('nik','like',"%{$search}%")->orWhere('employee_id','like',"%{$search}%")); 
        }
        
        // Apply filters
        foreach (['building_id','division_id','position_id','employment_status'] as $filter) { 
            if ($request->filled($filter)) $baseQuery->where($filter, $request->input($filter)); 
        }
        if ($request->filled('door_id')) { 
            $doorId=$request->input('door_id'); 
            $baseQuery->whereHas('doors', fn($q) => $q->where('doors.door_id',$doorId)->orWhere('doors.id',$doorId)); 
        }
        
        $employees = $baseQuery->paginate(min(max((int)$request->get('per_page',20),1),100));
        
        return response()->json([
            'status'=>'success',
            'pagination'=>[
                'current_page'=>$employees->currentPage(),
                'per_page'=>$employees->perPage(),
                'from'=>$employees->firstItem() ?? 0,
                'to'=>$employees->lastItem() ?? 0,
                'total_records'=>$employees->total(),
                'total_all'=>$totalAll,
                'total_pages'=>$employees->lastPage()
            ],
            'data'=>EmployeeResource::collection($employees)
        ]);
    }

    #[OA\Post(
        path: '/user-management/employees',
        summary: 'Tambah Karyawan Baru',
        description: 'Membuat data induk karyawan baru beserta status biometrik awal dan alokasi pintu.',
        tags: ['Employee'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'email'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Budi Santoso'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'budi.santoso@example.com'),
                    new OA\Property(property: 'nik', type: 'string', example: '3171012345670001'),
                    new OA\Property(property: 'phone', type: 'string', example: '08123456789'),
                    new OA\Property(property: 'card_no', type: 'string', example: 'CARD-99081'),
                    new OA\Property(property: 'role', type: 'string', example: 'Staff'),
                    new OA\Property(property: 'door_ids', type: 'array', items: new OA\Items(type: 'string'), example: ['DOOR-001'])
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Karyawan berhasil ditambahkan',
                content: new OA\JsonContent(
                    example: [
                        'status' => 'success',
                        'message' => 'Karyawan berhasil ditambahkan',
                        'data' => [
                            'id' => 1,
                            'employee_id' => 'USR-1001',
                            'name' => 'Budi Santoso',
                            'email' => 'budi.santoso@example.com'
                        ]
                    ]
                )
            ),
            new OA\Response(response: 422, description: 'Validasi input gagal')
        ]
    )]
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
        BiometricStatus::create(['employee_id'=>$employee->id,'has_fingerprint'=>$hasFp,'fingerprint_enrolled'=>$hasFp,'card_enrolled'=>$cardEnrolled,'biometric_template'=>null]);
        $this->assignInitialDoors($employee, $request->input('door_ids', []));
        $this->audit($request, 'create_employee', $employee, 'Created employee master record');
        return response()->json(['status'=>'success','message'=>'Karyawan berhasil ditambahkan','data'=>new EmployeeResource($employee->load(['biometricStatus','doors','building','division','position']))],201);
    }

    protected function findEmployeeByIdentifier($id): Employee { return Employee::where('id',$id)->orWhere('employee_id',$id)->firstOrFail(); }

    #[OA\Get(
        path: '/user-management/employees/{id}/360',
        summary: 'Profil 360 Karyawan',
        description: 'Mendapatkan pandangan komprehensif data karyawan 360 (organisasi, akses pintu, aset, skill, dan log aktivitas).',
        tags: ['Employee'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID internal atau Employee ID (misal USR-1001)', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Berhasil mengambil profil 360',
                content: new OA\JsonContent(
                    example: [
                        'status' => 'success',
                        'data' => [
                            'employee' => ['id' => 1, 'employee_id' => 'USR-1001', 'name' => 'Budi Santoso'],
                            'overview' => ['employment_status' => 'ACTIVE'],
                            'access' => ['assigned_doors' => []],
                            'skills' => [],
                            'tasks' => [],
                            'assets' => []
                        ]
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Karyawan tidak ditemukan')
        ]
    )]
    public function profile360(Request $request, $id)
    {
        $employee = $this->findEmployeeByIdentifier($id)->load([
            'biometricStatus','doors','building','division','position','supervisor','directReports',
            'accessLogs','credentials.deviceSyncs.door','accessRequests.accessProfile','emoneyCards',
            'assetAssignments.asset.category','assetIncidents.asset','skills.skill','skills.verifier','tasks.worklogs'
        ]);
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

        $assetsData = [];
        $assetIncidentsData = [];
        $skillsData = $employee->skills->map(fn($employeeSkill) => [
            'skill_id' => $employeeSkill->skill_id,
            'code' => $employeeSkill->skill?->code,
            'name' => $employeeSkill->skill?->name,
            'declared_level' => $employeeSkill->declared_level,
            'verified_level' => $employeeSkill->verified_level,
            'verified_by' => $employeeSkill->verifier?->only(['id', 'name']),
            'verified_at' => $employeeSkill->verified_at?->toIso8601String(),
        ]);
        $tasksData = $employee->tasks->map(fn($task) => [
            'id' => $task->id,
            'task_code' => $task->task_code,
            'title' => $task->title,
            'project_name' => $task->project_name,
            'priority' => $task->priority,
            'status' => $task->status,
            'progress' => $task->progress,
            'due_date' => $task->due_date?->toDateString(),
            'worklog_minutes' => $task->worklogs->sum('duration_minutes'),
        ]);
        if (!$isTechOnly && ($actor?->isSuperAdmin() || in_array(strtolower((string)$actor?->role), ['hrd', 'management'], true) || $actor?->id === $employee->id)) {
            $assetsData = $employee->assetAssignments->map(fn($a) => [
                'id' => $a->id,
                'assignment_number' => $a->assignment_number,
                'asset_code' => $a->asset?->asset_code,
                'asset_name' => $a->asset?->asset_name,
                'category' => $a->asset?->category?->name,
                'brand' => $a->asset?->brand,
                'model' => $a->asset?->model,
                'masked_serial_number' => $a->asset?->masked_serial_number,
                'assigned_at' => $a->assigned_at?->toDateString(),
                'expected_return_date' => $a->expected_return_date?->toDateString(),
                'actual_return_date' => $a->actual_return_date?->toDateString(),
                'status' => $a->status,
                'condition_out' => $a->condition_out,
                'condition_in' => $a->condition_in,
            ]);

            $assetIncidentsData = $employee->assetIncidents->map(fn($inc) => [
                'id' => $inc->id,
                'incident_number' => $inc->incident_number,
                'asset_code' => $inc->asset?->asset_code,
                'incident_type' => $inc->incident_type,
                'description' => $inc->description,
                'incident_date' => $inc->incident_date?->toDateString(),
                'status' => $inc->status,
                'resolution' => $inc->resolution,
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
            'skills'=>$skillsData,
            'tasks'=>$tasksData,
            'assets'=>$assetsData,
            'asset_incidents'=>$assetIncidentsData,
            'audit_summary'=>['access_log_count'=>$employee->accessLogs->count()]
        ]]);
    }

    #[OA\Get(
        path: '/user-management/employees/{id}',
        summary: 'Detail Karyawan',
        description: 'Mendapatkan detail karyawan spesifik berdasarkan ID.',
        tags: ['Employee'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID internal atau Employee ID', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Detail karyawan ditemukan',
                content: new OA\JsonContent(
                    example: [
                        'status' => 'success',
                        'data' => ['id' => 1, 'employee_id' => 'USR-1001', 'name' => 'Budi Santoso']
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Karyawan tidak ditemukan')
        ]
    )]
    public function show($id) { $employee=$this->findEmployeeByIdentifier($id)->load(['biometricStatus','doors','building','division','position']); $this->authorize('view',$employee); return response()->json(['status'=>'success','data'=>new EmployeeResource($employee)]); }

    #[OA\Put(
        path: '/user-management/employees/{id}',
        summary: 'Perbarui Data Karyawan',
        description: 'Memperbarui informasi data induk karyawan.',
        tags: ['Employee'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID internal atau Employee ID', required: true, schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Budi Santoso Update'),
                    new OA\Property(property: 'employment_status', type: 'string', example: 'ACTIVE')
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Data karyawan berhasil diperbarui',
                content: new OA\JsonContent(
                    example: [
                        'status' => 'success',
                        'message' => 'Data karyawan berhasil diperbarui'
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Karyawan tidak ditemukan')
        ]
    )]
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

    #[OA\Delete(
        path: '/user-management/employees/{id}',
        summary: 'Nonaktifkan Karyawan',
        description: 'Mengubah status karyawan menjadi INACTIVE dan mencabut semua hak akses pintu.',
        tags: ['Employee'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID internal atau Employee ID', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Karyawan berhasil dinonaktifkan',
                content: new OA\JsonContent(
                    example: [
                        'status' => 'success',
                        'message' => 'Karyawan dinonaktifkan; riwayat akses tetap tersimpan.'
                    ]
                )
            )
        ]
    )]
    public function destroy(Request $request, $id) { $employee=$this->findEmployeeByIdentifier($id); $this->authorize('delete',$employee); $employee->update(['employment_status'=>'INACTIVE']); app(\App\Services\AccessProvisioningService::class)->revokeEmployeeAccess($employee, 'Karyawan dinonaktifkan', $request->user()); $this->audit($request,'deactivate_employee',$employee,'Marked employee inactive; historical access logs preserved'); return response()->json(['status'=>'success','message'=>'Karyawan dinonaktifkan; riwayat akses tetap tersimpan.']); }

    #[OA\Post(
        path: '/user-management/employees/{id}/door-access',
        summary: 'Berikan Hak Akses Pintu',
        description: 'Memberikan hak akses pintu spesifik kepada karyawan.',
        tags: ['Employee'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID internal atau Employee ID', required: true, schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['door_id'],
                properties: [
                    new OA\Property(property: 'door_id', type: 'string', example: 'DOOR-001')
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Hak akses berhasil diberikan',
                content: new OA\JsonContent(
                    example: [
                        'status' => 'success',
                        'message' => 'Hak akses Pintu Utama Server berhasil diberikan. Sinkronisasi ke perangkat sedang diproses.',
                        'data' => ['employee_id' => 'USR-1001', 'door_id' => 'DOOR-001', 'sync_status' => 'pending']
                    ]
                )
            )
        ]
    )]
    public function assignDoorAccess(AssignDoorAccessRequest $request, $id) { $employee=$this->findEmployeeByIdentifier($id); $door=Door::where('door_id',$request->door_id)->orWhere('id',$request->door_id)->firstOrFail(); $this->authorize('assignDoor',[$employee,$door]); $assignment=DoorAssignment::updateOrCreate(['employee_id'=>$employee->id,'door_id'=>$door->id],['sync_status'=>'pending','sync_attempts'=>0]); SyncDoorAccessJob::dispatch($assignment->id); $this->audit($request,'assign_door_access',$employee,"Assigned door {$door->door_id}"); return response()->json(['status'=>'success','message'=>"Hak akses {$door->door_name} berhasil diberikan. Sinkronisasi ke perangkat sedang diproses.",'data'=>['employee_id'=>$employee->employee_id,'door_id'=>$door->door_id,'sync_status'=>$assignment->sync_status]]); }

    #[OA\Delete(
        path: '/user-management/employees/{id}/door-access/{door_id}',
        summary: 'Cabut Hak Akses Pintu',
        description: 'Mencabut hak akses pintu spesifik dari karyawan.',
        tags: ['Employee'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID internal atau Employee ID', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'door_id', in: 'path', description: 'ID Pintu', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Hak akses berhasil dicabut',
                content: new OA\JsonContent(
                    example: [
                        'status' => 'success',
                        'message' => 'Hak akses Pintu Utama Server berhasil dicabut.'
                    ]
                )
            )
        ]
    )]
    #[OA\Post(
        path: '/user-management/employees/{id}/enroll-card',
        summary: 'Enroll Kartu Akses RFID Baru',
        description: 'Mendaftarkan kartu RFID baru untuk karyawan, memverifikasi keunikan kartu, menerbitkan CredentialRecord, dan mentrigger sinkronisasi perangkat.',
        tags: ['Employee'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID internal atau Employee ID', required: true, schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['card_number'],
                properties: [
                    new OA\Property(property: 'card_number', type: 'string', example: 'CARD-100234'),
                    new OA\Property(property: 'notes', type: 'string', example: 'Kartu fisik diterbitkan oleh HR')
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Kartu berhasil didaftarkan dan di-queue untuk sinkronisasi perangkat',
                content: new OA\JsonContent(
                    example: [
                        'status' => 'success',
                        'message' => 'Kartu CARD-100234 berhasil didaftarkan untuk Budi Santoso.',
                        'data' => [
                            'employee_id' => 'USR-1001',
                            'card_number' => 'CARD-100234',
                            'credential_number' => 'CRD-2026-0001',
                            'status' => 'ACTIVE'
                        ]
                    ]
                )
            ),
            new OA\Response(response: 422, description: 'Nomor kartu tidak valid atau sudah terdaftar')
        ]
    )]
    public function enrollCard(Request $request, $id)
    {
        $employee = $this->findEmployeeByIdentifier($id);
        $this->authorize('update', $employee);

        $request->validate([
            'card_number' => 'required|string|min:4|max:64',
            'notes' => 'nullable|string|max:255',
        ]);

        $cardNumber = trim($request->input('card_number'));

        // Check uniqueness across active credentials and employee card_no
        $existingCredential = \App\Models\CredentialRecord::where('card_number', $cardNumber)
            ->whereIn('status', ['ACTIVE', 'PENDING'])
            ->first();

        $existingEmployee = Employee::where('card_no', $cardNumber)
            ->where('id', '!=', $employee->id)
            ->first();

        if ($existingCredential || $existingEmployee) {
            return response()->json([
                'status' => 'error',
                'code' => 422,
                'message' => 'Nomor kartu ini sudah terdaftar dan aktif untuk pengguna lain.',
                'errors' => [
                    'card_number' => ['Nomor kartu ini sudah terdaftar dan aktif untuk pengguna lain.']
                ]
            ], 422);
        }

        // Update employee card_no
        $employee->card_no = $cardNumber;
        $employee->save();

        // Issue credential record using AccessProvisioningService
        $service = app(\App\Services\AccessProvisioningService::class);
        $credential = $service->issueCredential([
            'employee_id' => $employee->id,
            'credential_type' => 'CARD',
            'card_number' => $cardNumber,
            'status' => 'ACTIVE',
            'notes' => $request->input('notes') ?? 'Web Card Enrollment',
        ], $request->user());

        // Dispatch sync job for all assigned doors
        $assignments = DoorAssignment::where('employee_id', $employee->id)->get();
        foreach ($assignments as $assignment) {
            $assignment->update(['sync_status' => 'pending']);
            SyncDoorAccessJob::dispatch($assignment->id);
        }

        $this->audit($request, 'card_enrolled', $employee, "Enrolled new RFID card {$cardNumber} for {$employee->name}");

        return response()->json([
            'status' => 'success',
            'message' => "Kartu {$cardNumber} berhasil didaftarkan untuk {$employee->name}.",
            'data' => [
                'employee_id' => $employee->employee_id,
                'card_number' => $cardNumber,
                'credential_number' => $credential->credential_number,
                'status' => $credential->status,
            ]
        ]);
    }

    #[OA\Post(
        path: '/user-management/employees/{id}/block-lost-card',
        summary: 'Blokir Kartu Hilang & Revoke Akses Pintu',
        description: 'Memblokir kartu yang hilang, mencabut seluruh hak akses pintu karyawan terkait, dan mencatat audit log lengkap.',
        tags: ['Employee'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID internal atau Employee ID', required: true, schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason'],
                properties: [
                    new OA\Property(property: 'reason', type: 'string', example: 'Kartu hilang di area parkir'),
                    new OA\Property(property: 'card_number', type: 'string', example: 'CARD-100234')
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Kartu berhasil diblokir dan seluruh akses pintu dicabut',
                content: new OA\JsonContent(
                    example: [
                        'status' => 'success',
                        'message' => 'Kartu berhasil diblokir dan 3 akses pintu untuk Budi Santoso berhasil dicabut.',
                        'revoked_doors_count' => 3
                    ]
                )
            )
        ]
    )]
    public function blockLostCard(Request $request, $id)
    {
        $employee = $this->findEmployeeByIdentifier($id);
        $this->authorize('update', $employee);

        $request->validate([
            'reason' => 'required|string|max:255',
            'card_number' => 'nullable|string',
        ]);

        $reason = $request->input('reason');
        $cardNumber = $request->input('card_number') ?? $employee->card_no;

        // 1. Mark CredentialRecord status as BLOCKED
        $query = \App\Models\CredentialRecord::where('employee_id', $employee->id);
        if ($cardNumber) {
            $query->where(function ($q) use ($cardNumber) {
                $q->where('card_number', $cardNumber)->orWhere('masked_identifier', 'LIKE', '%' . substr($cardNumber, -4));
            });
        }
        $credentials = $query->get();

        if ($credentials->isEmpty()) {
            $credentials = \App\Models\CredentialRecord::where('employee_id', $employee->id)->where('status', 'ACTIVE')->get();
        }

        foreach ($credentials as $crd) {
            $crd->status = 'BLOCKED';
            $crd->revoked_at = now();
            $crd->revocation_reason = 'BLOCKED_LOST: ' . $reason;
            $crd->save();

            // Enqueue device revocation sync
            $service = app(\App\Services\AccessProvisioningService::class);
            $doors = Door::all();
            foreach ($doors as $door) {
                $service->enqueueDeviceSync($crd, $door, 'REVOKE');
            }
        }

        // 2. Clear employee card_no if matching
        if ($employee->card_no && ($cardNumber === null || $employee->card_no === $cardNumber)) {
            $employee->card_no = null;
            $employee->save();
        }

        // 3. Revoke ALL door access for this employee
        $assignments = DoorAssignment::where('employee_id', $employee->id)->get();
        $revokedCount = DoorAssignment::whereKey($assignments->modelKeys())->delete();

        // 4. Record comprehensive ActivityLog
        ActivityLog::create([
            'admin_id' => $request->user()?->id,
            'action' => 'card_blocked_lost',
            'subject_type' => 'Employee',
            'subject_id' => $employee->id,
            'description' => "Kartu " . ($cardNumber ? "{$cardNumber} " : "") . "milik {$employee->name} ({$employee->employee_id}) diblokir karena hilang/dicuri. Alasan: {$reason}. {$revokedCount} hak akses pintu dicabut.",
            'timestamp' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Kartu " . ($cardNumber ? "({$cardNumber}) " : "") . "berhasil diblokir dan {$revokedCount} hak akses pintu untuk {$employee->name} dicabut.",
            'revoked_doors_count' => $revokedCount,
            'data' => [
                'employee_id' => $employee->employee_id,
                'status' => 'BLOCKED',
                'revoked_doors_count' => $revokedCount,
            ]
        ]);
    }

    private function assignInitialDoors(Employee $employee,array $doorIds): void { foreach (Door::whereIn('door_id',$doorIds)->orWhereIn('id',$doorIds)->get() as $door) { $assignment=DoorAssignment::firstOrCreate(['employee_id'=>$employee->id,'door_id'=>$door->id],['sync_status'=>'pending','sync_attempts'=>'0']); SyncDoorAccessJob::dispatch($assignment->id); } }
    private function audit(Request $request,string $action,Employee $employee,string $description): void { ActivityLog::create(['admin_id'=>$request->user()?->id,'action'=>$action,'subject_type'=>'Employee','subject_id'=>$employee->id,'description'=>$description.' ['.$employee->employee_id.']','timestamp'=>now()]); }
}
