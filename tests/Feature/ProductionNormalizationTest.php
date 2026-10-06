<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Door;
use App\Models\DoorAssignment;
use App\Models\Employee;
use App\Services\BuildingAccessService;
use App\Services\ProductionNormalizationService;
use App\Services\CardSecurityService;
use Tests\TestCase;

class ProductionNormalizationTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected ProductionNormalizationService $normalization;
    protected CardSecurityService $cardSecurity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalization = new ProductionNormalizationService();
        $this->cardSecurity = new CardSecurityService();
    }

    /**
     * Test Real96 audit structure is valid.
     */
    public function test_real96_audit_structure_valid(): void
    {
        $audit = $this->normalization->auditReal96Normalization();

        $this->assertArrayHasKey('total_employees', $audit);
        $this->assertArrayHasKey('real_employees', $audit);
        $this->assertArrayHasKey('dummy_employees', $audit);
        $this->assertArrayHasKey('target_state', $audit);
        $this->assertIsArray($audit['dummy_person_nos']);
        $this->assertCount(12, $audit['dummy_person_nos']);
    }

    /**
     * Test card hash generation is deterministic.
     */
    public function test_card_hash_is_deterministic(): void
    {
        $card = '1234567890';
        
        $hash1 = $this->cardSecurity->hashCardNumber($card);
        $hash2 = $this->cardSecurity->hashCardNumber($card);
        
        $this->assertEquals($hash1, $hash2, 'Hash should be deterministic');
    }

    /**
     * Test card masking for UI.
     */
    public function test_card_masking_shows_last_four(): void
    {
        $masked = $this->cardSecurity->maskCardNumber('1234567890');
        
        $this->assertEquals('****7890', $masked);
        $this->assertStringNotContainsString('1234567890', $masked);
    }

    /**
     * Test Building B audit requires building to exist.
     */
    public function test_building_b_audit_when_building_missing(): void
    {
        // Building B might not exist in test DB
        $audit = $this->normalization->auditBuildingBEntitlement();

        // Verify audit structure regardless
        $this->assertArrayHasKey('building_name', $audit);
        if (isset($audit['error'])) {
            $this->assertStringContainsString('not found', $audit['error']);
        } else {
            $this->assertTrue($audit['invariant_valid']);
        }
    }

    /**
     * Test write operations are protected by authorization flag.
     */
    public function test_write_operations_default_deny(): void
    {
        // Default (false authorization) should return protection response
        $result = $this->normalization->normalizeReal96();
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
    }

    /**
     * Dummies are identified by employees.employee_id (there is no person_no column) and
     * eligibility by employment_status (there is no is_active column). On SQLite the old
     * column names silently matched nothing; on PostgreSQL they raised an error.
     */
    public function test_dummy_and_eligibility_use_existing_employee_columns(): void
    {
        $dummy = $this->employee('USR-1001', 'ACTIVE');
        $active = $this->employee('EMP-REAL-1', 'ACTIVE');
        $lowercase = $this->employee('EMP-REAL-2', 'active');
        $inactive = $this->employee('EMP-REAL-4', 'INACTIVE');

        $buildingB = Building::create(['code' => 'BUILDING_B', 'name' => 'Building B', 'is_active' => true]);
        $door = Door::create([
            'door_id' => 'DOOR-NORM-B',
            'name' => 'Pintu Gedung B',
            'door_name' => 'Pintu Gedung B',
            'location' => 'Gedung B',
            'ip_address' => '192.168.9.50',
            'status' => 'online',
            'connection_status' => 'online',
            'building_id' => $buildingB->id,
        ]);

        $audit = $this->normalization->auditReal96Normalization();
        $this->assertSame(4, $audit['total_employees']);
        $this->assertSame(1, $audit['dummy_employees']);
        $this->assertSame([$dummy->id], $audit['dummy_ids_found']);

        $buildingAccess = new BuildingAccessService();
        $this->assertSame(1, $buildingAccess->getDummyEmployeeCount());
        $this->assertSame(2, $buildingAccess->getBuildingAccessMetrics($buildingB)['eligible_count']);

        $assigned = $this->normalization->assignAllEligibleToBuildingB(true);
        $this->assertSame([], $assigned['errors']);
        $this->assertSame(2, $assigned['created_assignments']);
        $this->assertEqualsCanonicalizing(
            [$active->id, $lowercase->id],
            DoorAssignment::where('door_id', $door->id)->pluck('employee_id')->all()
        );

        DoorAssignment::create(['employee_id' => $dummy->id, 'door_id' => $door->id, 'sync_status' => 'pending']);
        $normalized = $this->normalization->normalizeReal96(true);
        $this->assertSame([], $normalized['errors']);
        $this->assertSame(1, $normalized['dummy_employees_deleted']);
        $this->assertSame(1, $normalized['door_assignments_deleted']);
        $this->assertSoftDeleted('employees', ['id' => $dummy->id]);
        $this->assertNotSoftDeleted('employees', ['id' => $inactive->id]);
    }

    private function employee(string $code, string $status): Employee
    {
        return Employee::create([
            'employee_id' => $code,
            'nik' => 'NIK-' . $code,
            'name' => 'Pegawai ' . $code,
            'department' => 'IT',
            'employment_status' => $status,
        ]);
    }

    /**
     * Test card audit is read-only.
     */
    public function test_card_audit_is_read_only(): void
    {
        $audit1 = $this->cardSecurity->auditCardHashing();
        $audit2 = $this->cardSecurity->auditCardHashing();
        
        $this->assertEquals($audit1, $audit2, 'Audit should not modify state');
    }
}
