<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Building;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeMasterDataTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;
    protected function setUp(): void { parent::setUp(); $this->admin=Admin::create(['name'=>'HR Admin','email'=>'hr@example.test','password'=>bcrypt('password'),'role'=>'super_admin']); Sanctum::actingAs($this->admin); }

    public function test_employee_master_stores_organization_and_employment_data_without_biometric_template(): void
    {
        $building=Building::create(['code'=>'BLD-A','name'=>'Gedung A']); $division=Division::create(['code'=>'DIV-IT','name'=>'IT','building_id'=>$building->id]); $position=Position::create(['code'=>'POS-ENG','name'=>'Engineer','division_id'=>$division->id]);
        $response=$this->postJson('/api/v1/user-management/employees',['employee_id'=>'HIK-1001','nik'=>'NIK-1001','name'=>'Ayu','department'=>'IT','email'=>'ayu@example.test','building_id'=>$building->id,'division_id'=>$division->id,'position_id'=>$position->id,'employment_type'=>'PERMANENT','employment_status'=>'ACTIVE','hire_date'=>'2026-01-01','fingerprint_enrolled'=>true]);
        $response->assertCreated()->assertJsonPath('data.hikvision_employee_no','HIK-1001')->assertJsonPath('data.building.name','Gedung A');
        $employee=Employee::firstOrFail(); $this->assertDatabaseHas('employees',['id'=>$employee->id,'employment_status'=>'ACTIVE','building_id'=>$building->id]); $this->assertNull($employee->biometricStatus->biometric_template);
    }

    public function test_inactive_employee_preserves_the_record_and_history_linkage(): void
    {
        $employee=Employee::create(['employee_id'=>'HIK-1002','hikvision_employee_no'=>'HIK-1002','nik'=>'NIK-1002','name'=>'Bima','department'=>'Ops','employment_status'=>'ACTIVE']);
        $this->deleteJson('/api/v1/user-management/employees/'.$employee->id)->assertOk();
        $this->assertDatabaseHas('employees',['id'=>$employee->id,'employment_status'=>'INACTIVE']); $this->assertDatabaseMissing('employees',['id'=>$employee->id,'deleted_at'=>now()]);
    }

    public function test_non_super_admin_cannot_manage_organization_master(): void
    {
        $admin=Admin::create(['name'=>'Building','email'=>'building@example.test','password'=>bcrypt('password'),'role'=>'building_admin','assigned_building'=>'Gedung A']); Sanctum::actingAs($admin);
        $this->postJson('/api/v1/user-management/organization/buildings',['code'=>'BLD-X','name'=>'Gedung X'])->assertForbidden();
    }
}
