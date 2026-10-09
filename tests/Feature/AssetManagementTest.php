<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetCategory;
use App\Models\AssetIncident;
use App\Models\AssetMaintenance;
use App\Models\Building;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Internship;
use App\Models\OnboardingCase;
use App\Models\OnboardingTask;
use App\Services\AssetService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssetManagementTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $superadmin;
    protected Admin $hrd;
    protected Admin $buildingAdminA;
    protected Admin $buildingAdminB;
    protected Admin $employeeUser;
    protected Admin $otherEmployeeUser;
    protected Admin $developer;

    protected Employee $employeeA;
    protected Employee $employeeB;
    protected Employee $inactiveEmployee;
    protected Internship $activeIntern;
    protected Building $buildingA;
    protected Building $buildingB;
    protected AssetCategory $categoryLaptop;

    protected function setUp(): void
    {
        parent::setUp();

        // Buildings
        $this->buildingA = Building::create(['code' => 'BDG-HQ', 'name' => 'Kantor Pusat PKP', 'address' => 'Jl. Sudirman No. 1']);
        $this->buildingB = Building::create(['code' => 'BDG-SBY', 'name' => 'Gedung Cabang Surabaya', 'address' => 'Jl. Pemuda No. 10']);

        // Admins & Roles
        $this->superadmin = Admin::create([
            'name' => 'Super Administrator',
            'email' => 'superadmin@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
        ]);

        $this->hrd = Admin::create([
            'name' => 'HRD Officer',
            'email' => 'hrd@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'hrd',
        ]);

        $this->buildingAdminA = Admin::create([
            'name' => 'Building Admin HQ',
            'email' => 'admin.hq@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'building_admin',
            'assigned_building' => 'Kantor Pusat PKP',
        ]);

        $this->buildingAdminB = Admin::create([
            'name' => 'Building Admin Surabaya',
            'email' => 'admin.sby@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'building_admin',
            'assigned_building' => 'Gedung Cabang Surabaya',
        ]);

        $this->developer = Admin::create([
            'name' => 'Technical Developer',
            'email' => 'dev@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'developer',
        ]);

        // Employees
        $this->employeeA = Employee::create([
            'employee_id' => 'EMP-001',
            'nik' => 'NIK-001',
            'name' => 'Ahmad Budi',
            'email' => 'ahmad@pkp.co.id',
            'department' => 'IT',
            'employment_status' => 'ACTIVE',
            'employment_type' => 'PERMANENT',
            'building_id' => $this->buildingA->id,
        ]);

        $this->employeeB = Employee::create([
            'employee_id' => 'EMP-002',
            'nik' => 'NIK-002',
            'name' => 'Budi Santoso',
            'email' => 'budi@pkp.co.id',
            'department' => 'Operations',
            'employment_status' => 'ACTIVE',
            'employment_type' => 'PERMANENT',
            'building_id' => $this->buildingB->id,
        ]);

        $this->inactiveEmployee = Employee::create([
            'employee_id' => 'EMP-003',
            'nik' => 'NIK-003',
            'name' => 'Mantan Pegawai',
            'email' => 'mantan@pkp.co.id',
            'department' => 'HR',
            'employment_status' => 'INACTIVE',
            'employment_type' => 'PERMANENT',
        ]);

        // Employee user accounts (for self-service testing)
        $this->employeeUser = Admin::create([
            'name' => $this->employeeA->name,
            'email' => $this->employeeA->email,
            'password' => Hash::make('password'),
            'role' => 'employee',
            'employee_id' => $this->employeeA->id,
        ]);

        $this->otherEmployeeUser = Admin::create([
            'name' => $this->employeeB->name,
            'email' => $this->employeeB->email,
            'password' => Hash::make('password'),
            'role' => 'employee',
            'employee_id' => $this->employeeB->id,
        ]);

        // Internship
        $this->activeIntern = Internship::create([
            'intern_id' => 'INT-2026-001',
            'institution' => 'Institut Teknologi PKP',
            'major' => 'Informatika',
            'start_date' => Carbon::today()->toDateString(),
            'end_date' => Carbon::today()->addMonths(3)->toDateString(),
            'status' => 'ACTIVE',
        ]);

        // Auto seed standard categories
        app(AssetService::class)->ensureStandardCategories();
        $this->categoryLaptop = AssetCategory::where('code', 'LAPTOP')->firstOrFail();
    }

    public function test_can_list_and_create_asset_master(): void
    {
        Sanctum::actingAs($this->superadmin);

        $res = $this->getJson('/api/v1/assets');
        $res->assertStatus(200)
            ->assertJsonPath('success', true);

        $createRes = $this->postJson('/api/v1/assets', [
            'asset_name' => 'MacBook Pro 16 M3 Max',
            'category_id' => $this->categoryLaptop->id,
            'brand' => 'Apple',
            'model' => 'MacBook Pro 16',
            'serial_number' => 'C02ABC123XYZ',
            'purchase_date' => '2026-01-15',
            'purchase_price' => 45000000,
            'vendor' => 'iBox Indonesia',
            'building_name' => 'Kantor Pusat PKP',
            'location' => 'Lantai 3 Ruang IT',
            'condition' => 'NEW',
        ]);

        $createRes->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.asset_name', 'MacBook Pro 16 M3 Max')
            ->assertJsonPath('data.status', 'AVAILABLE');

        $this->assertDatabaseHas('assets', [
            'serial_number' => 'C02ABC123XYZ',
            'status' => 'AVAILABLE',
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'asset_created',
        ]);
    }

    public function test_duplicate_serial_number_is_denied(): void
    {
        Sanctum::actingAs($this->superadmin);

        $this->postJson('/api/v1/assets', [
            'asset_name' => 'ThinkPad T14 Gen 4',
            'category_id' => $this->categoryLaptop->id,
            'serial_number' => 'SN-DUPLICATE-999',
            'building_name' => 'Kantor Pusat PKP',
        ])->assertStatus(201);

        $dupRes = $this->postJson('/api/v1/assets', [
            'asset_name' => 'ThinkPad T14 Gen 4 Unit 2',
            'category_id' => $this->categoryLaptop->id,
            'serial_number' => 'SN-DUPLICATE-999',
            'building_name' => 'Kantor Pusat PKP',
        ]);

        $dupRes->assertStatus(422)
            ->assertJsonValidationErrors(['serial_number']);
    }

    public function test_can_assign_asset_to_active_employee(): void
    {
        Sanctum::actingAs($this->hrd);

        $asset = Asset::create([
            'asset_code' => 'AST-202609-0010',
            'asset_name' => 'Dell Latitude 7440',
            'category_id' => $this->categoryLaptop->id,
            'serial_number' => 'DL-99881122',
            'building_name' => 'Kantor Pusat PKP',
            'status' => 'AVAILABLE',
            'condition' => 'GOOD',
        ]);

        $assignRes = $this->postJson("/api/v1/assets/{$asset->id}/assign", [
            'employee_id' => $this->employeeA->id,
            'expected_return_date' => Carbon::now()->addYear()->toDateString(),
            'condition_out' => 'GOOD',
            'accessories' => ['charger' => true, 'bag' => true, 'mouse' => true],
            'handover_notes' => 'Laptop inventaris dinas staf IT.',
        ]);

        $assignRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ACTIVE');

        $this->assertEquals('ASSIGNED', $asset->fresh()->status);
        $this->assertDatabaseHas('asset_assignments', [
            'asset_id' => $asset->id,
            'employee_id' => $this->employeeA->id,
            'status' => 'ACTIVE',
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'asset_assigned',
        ]);
    }

    public function test_cannot_assign_already_assigned_asset(): void
    {
        Sanctum::actingAs($this->hrd);

        $asset = Asset::create([
            'asset_code' => 'AST-202609-0011',
            'asset_name' => 'Dell Latitude 7440 Assigned',
            'category_id' => $this->categoryLaptop->id,
            'serial_number' => 'DL-ALREADY-ASSIGNED',
            'building_name' => 'Kantor Pusat PKP',
            'status' => 'ASSIGNED',
            'condition' => 'GOOD',
        ]);

        $res = $this->postJson("/api/v1/assets/{$asset->id}/assign", [
            'employee_id' => $this->employeeA->id,
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['asset_id']);
    }

    public function test_cannot_assign_asset_to_inactive_employee(): void
    {
        Sanctum::actingAs($this->hrd);

        $asset = Asset::create([
            'asset_code' => 'AST-202609-0012',
            'asset_name' => 'ThinkPad P14s',
            'category_id' => $this->categoryLaptop->id,
            'serial_number' => 'TP-INACTIVE-TEST',
            'building_name' => 'Kantor Pusat PKP',
            'status' => 'AVAILABLE',
            'condition' => 'GOOD',
        ]);

        $res = $this->postJson("/api/v1/assets/{$asset->id}/assign", [
            'employee_id' => $this->inactiveEmployee->id,
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['employee_id']);
    }

    public function test_can_assign_asset_to_intern(): void
    {
        Sanctum::actingAs($this->hrd);

        $asset = Asset::create([
            'asset_code' => 'AST-202609-0013',
            'asset_name' => 'ThinkPad E14 Pemagang',
            'category_id' => $this->categoryLaptop->id,
            'serial_number' => 'TP-INTERN-01',
            'building_name' => 'Kantor Pusat PKP',
            'status' => 'AVAILABLE',
            'condition' => 'GOOD',
        ]);

        $res = $this->postJson("/api/v1/assets/{$asset->id}/assign", [
            'internship_id' => $this->activeIntern->id,
            'expected_return_date' => $this->activeIntern->end_date,
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertEquals('ASSIGNED', $asset->fresh()->status);
        $this->assertDatabaseHas('asset_assignments', [
            'asset_id' => $asset->id,
            'internship_id' => $this->activeIntern->id,
            'status' => 'ACTIVE',
        ]);
    }

    public function test_building_admin_cannot_access_or_assign_other_building_asset(): void
    {
        // Asset in Surabaya
        $assetSby = Asset::create([
            'asset_code' => 'AST-202609-0014',
            'asset_name' => 'Monitor Samsung 27 Surabaya',
            'serial_number' => 'MON-SBY-001',
            'building_name' => 'Gedung Cabang Surabaya',
            'status' => 'AVAILABLE',
            'condition' => 'GOOD',
        ]);

        // Building Admin A is HQ only
        Sanctum::actingAs($this->buildingAdminA);

        $showRes = $this->getJson("/api/v1/assets/{$assetSby->id}");
        $showRes->assertStatus(403);

        $assignRes = $this->postJson("/api/v1/assets/{$assetSby->id}/assign", [
            'employee_id' => $this->employeeA->id,
        ]);
        $assignRes->assertStatus(403);
    }

    public function test_employee_can_view_own_asset_but_cannot_view_others_or_perform_admin_actions(): void
    {
        $asset = Asset::create([
            'asset_code' => 'AST-202609-0015',
            'asset_name' => 'MacBook Air M2',
            'serial_number' => 'MBA-M2-777888',
            'building_name' => 'Kantor Pusat PKP',
            'status' => 'ASSIGNED',
            'condition' => 'GOOD',
        ]);

        $assignment = AssetAssignment::create([
            'assignment_number' => 'ASG-202609-0001',
            'asset_id' => $asset->id,
            'employee_id' => $this->employeeA->id,
            'assigned_by' => $this->hrd->id,
            'assigned_at' => now(),
            'status' => 'ACTIVE',
        ]);

        // Acting as Employee A
        Sanctum::actingAs($this->employeeUser);

        // Can view own asset
        $ownRes = $this->getJson("/api/v1/assets/{$asset->id}");
        $ownRes->assertStatus(200)
            ->assertJsonPath('data.asset_code', 'AST-202609-0015');

        // Cannot create new asset
        $createRes = $this->postJson('/api/v1/assets', [
            'asset_name' => 'Unauthorized Asset',
        ]);
        $createRes->assertStatus(403);

        // Acting as Employee B (other employee)
        Sanctum::actingAs($this->otherEmployeeUser);

        // Denied IDOR view
        $otherRes = $this->getJson("/api/v1/assets/{$asset->id}");
        $otherRes->assertStatus(403);
    }

    public function test_technical_role_cannot_view_broad_employee_assets_without_permission(): void
    {
        Sanctum::actingAs($this->developer);

        // Developer does not have asset.view
        $res = $this->getJson('/api/v1/assets');
        $res->assertStatus(403);
    }

    public function test_asset_return_workflow_and_condition_tracking(): void
    {
        Sanctum::actingAs($this->hrd);

        $asset = Asset::create([
            'asset_code' => 'AST-202609-0016',
            'asset_name' => 'iPad Pro 11 M4',
            'serial_number' => 'IPAD-PRO-11',
            'building_name' => 'Kantor Pusat PKP',
            'status' => 'ASSIGNED',
            'condition' => 'GOOD',
        ]);

        $assignment = AssetAssignment::create([
            'assignment_number' => 'ASG-202609-0002',
            'asset_id' => $asset->id,
            'employee_id' => $this->employeeA->id,
            'assigned_by' => $this->hrd->id,
            'assigned_at' => now()->subMonths(6),
            'status' => 'ACTIVE',
        ]);

        // Return asset in DAMAGED condition -> should automatically mark asset status as MAINTENANCE
        $returnRes = $this->postJson("/api/v1/assets/assignments/{$assignment->id}/return", [
            'condition_in' => 'DAMAGED',
            'return_notes' => 'Layar retak saat dinas luar kota.',
        ]);

        $returnRes->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertEquals('RETURNED', $assignment->fresh()->status);
        $this->assertEquals('MAINTENANCE', $asset->fresh()->status);
        $this->assertEquals('DAMAGED', $asset->fresh()->condition);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'asset_returned',
        ]);
    }

    public function test_maintenance_lifecycle(): void
    {
        Sanctum::actingAs($this->buildingAdminA);

        $asset = Asset::create([
            'asset_code' => 'AST-202609-0017',
            'asset_name' => 'Router Cisco Catalyst 9200',
            'serial_number' => 'CISCO-CAT-9200',
            'building_name' => 'Kantor Pusat PKP',
            'status' => 'AVAILABLE',
            'condition' => 'GOOD',
        ]);

        // Open maintenance
        $openRes = $this->postJson("/api/v1/assets/{$asset->id}/maintenance", [
            'maintenance_type' => 'PREVENTIVE',
            'issue_description' => 'Pembersihan debu dan upgrade firmware patch v17.9',
            'vendor' => 'Mitra Solusi Jaringan',
            'cost' => 1500000,
        ]);

        $openRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'OPEN');

        $this->assertEquals('MAINTENANCE', $asset->fresh()->status);
        $maintenanceId = $openRes->json('data.id');

        // Complete maintenance
        $compRes = $this->postJson("/api/v1/assets/maintenances/{$maintenanceId}/complete", [
            'result' => 'Upgrade berhasil diselesaikan, port switch stabil.',
            'condition' => 'GOOD',
        ]);

        $compRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'COMPLETED');

        $this->assertEquals('AVAILABLE', $asset->fresh()->status);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'asset_maintenance_completed',
        ]);
    }

    public function test_incident_reporting_and_resolution(): void
    {
        Sanctum::actingAs($this->buildingAdminA);

        $asset = Asset::create([
            'asset_code' => 'AST-202609-0018',
            'asset_name' => 'Drone DJI Mavic 3 Enterprise',
            'serial_number' => 'DJI-MAVIC-3E',
            'building_name' => 'Kantor Pusat PKP',
            'status' => 'AVAILABLE',
            'condition' => 'GOOD',
        ]);

        // Report incident (STOLEN)
        $incRes = $this->postJson("/api/v1/assets/{$asset->id}/incident", [
            'incident_type' => 'STOLEN',
            'description' => 'Hilang dari mobil operasional saat di lokasi proyek.',
            'location' => 'Area Parkir Proyek Cibubur',
            'employee_id' => $this->employeeA->id,
        ]);

        $incRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.incident_type', 'STOLEN');

        $this->assertEquals('LOST', $asset->fresh()->status);
        $incidentId = $incRes->json('data.id');

        // Resolve incident
        $resRes = $this->postJson("/api/v1/assets/incidents/{$incidentId}/resolve", [
            'resolution' => 'Laporan kepolisian dibuat, klaim asuransi telah diproses.',
        ]);

        $resRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'RESOLVED');

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'asset_incident_resolved',
        ]);
    }

    public function test_safe_disposal_prevents_active_assigned_asset_and_preserves_history(): void
    {
        Sanctum::actingAs($this->superadmin);

        $asset = Asset::create([
            'asset_code' => 'AST-202609-0019',
            'asset_name' => 'PC Server Lama',
            'serial_number' => 'SRV-OLD-2015',
            'building_name' => 'Kantor Pusat PKP',
            'status' => 'ASSIGNED',
            'condition' => 'POOR',
        ]);

        // Cannot dispose assigned asset
        $denyRes = $this->postJson("/api/v1/assets/{$asset->id}/dispose", [
            'disposal_reason' => 'Unit usang',
        ]);
        $denyRes->assertStatus(422)
            ->assertJsonValidationErrors(['asset_id']);

        // Set status to AVAILABLE
        $asset->update(['status' => 'AVAILABLE']);

        // Now disposal succeeds
        $dispRes = $this->postJson("/api/v1/assets/{$asset->id}/dispose", [
            'disposal_reason' => 'Habis masa manfaat teknis 10 tahun.',
            'disposal_method' => 'SCRAP_RECYCLE',
        ]);

        $dispRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'DISPOSED');

        $this->assertEquals('DISPOSED', $asset->fresh()->status);
        $this->assertNotNull($asset->fresh()->disposed_at);
        $this->assertDatabaseHas('assets', ['id' => $asset->id]); // Record preserved!
    }

    public function test_employee_360_includes_authorized_asset_data(): void
    {
        $asset = Asset::create([
            'asset_code' => 'AST-202609-0020',
            'asset_name' => 'ThinkPad X1 Carbon Gen 11',
            'serial_number' => 'TP-X1C-99887766',
            'building_name' => 'Kantor Pusat PKP',
            'status' => 'ASSIGNED',
            'condition' => 'NEW',
        ]);

        AssetAssignment::create([
            'assignment_number' => 'ASG-202609-0003',
            'asset_id' => $asset->id,
            'employee_id' => $this->employeeA->id,
            'assigned_by' => $this->hrd->id,
            'assigned_at' => now(),
            'status' => 'ACTIVE',
        ]);

        Sanctum::actingAs($this->hrd);

        $res = $this->getJson("/api/v1/user-management/employees/{$this->employeeA->id}/360");
        $res->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(1, 'data.assets')
            ->assertJsonPath('data.assets.0.asset_code', 'AST-202609-0020')
            ->assertJsonPath('data.assets.0.masked_serial_number', '***********7766');
    }

    public function test_onboarding_asset_assignment_integration(): void
    {
        Sanctum::actingAs($this->hrd);

        $case = OnboardingCase::create([
            'case_number' => 'ONB-2026-0099',
            'employee_id' => $this->employeeA->id,
            'status' => 'IN_PROGRESS',
            'start_date' => Carbon::today()->toDateString(),
            'target_completion_date' => Carbon::today()->addWeeks(2)->toDateString(),
        ]);

        $task = OnboardingTask::create([
            'onboarding_case_id' => $case->id,
            'task_key' => 'asset_assignment',
            'title' => 'Penyerahan Perangkat Kerja & Inventaris Perusahaan',
            'category' => 'FACILITY',
            'required' => false,
            'status' => 'PENDING',
        ]);

        $asset = Asset::create([
            'asset_code' => 'AST-202609-0021',
            'asset_name' => 'Dell XPS 15 Onboarding Unit',
            'serial_number' => 'DELL-XPS-ONB',
            'building_name' => 'Kantor Pusat PKP',
            'status' => 'AVAILABLE',
            'condition' => 'NEW',
        ]);

        $assignment = app(AssetService::class)->assignOnboardingAsset($case, $asset, $this->hrd);

        $this->assertEquals('ASSIGNED', $asset->fresh()->status);
        $this->assertEquals('COMPLETED', $task->fresh()->status);
        $this->assertEquals($this->hrd->id, $task->fresh()->completed_by);
    }

    public function test_cannot_complete_internship_with_outstanding_unreturned_assets(): void
    {
        $asset = Asset::create([
            'asset_code' => 'AST-202609-0022',
            'asset_name' => 'Lenovo ThinkPad Intern Unit',
            'serial_number' => 'TP-INT-LOCK-01',
            'building_name' => 'Kantor Pusat PKP',
            'status' => 'AVAILABLE',
            'condition' => 'GOOD',
        ]);

        app(AssetService::class)->assignAsset($asset, [
            'internship_id' => $this->activeIntern->id,
        ], $this->hrd);

        Sanctum::actingAs($this->hrd);

        // Attempt to mark internship COMPLETED
        $res = $this->putJson("/api/v1/internships/{$this->activeIntern->id}", [
            'status' => 'COMPLETED',
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->assertEquals('ACTIVE', $this->activeIntern->fresh()->status);
    }
}
