<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Employee;
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
     * Test Real96 audit detects dummy employees.
     */
    public function test_real96_audit_identifies_dummy_employees(): void
    {
        // Create real employee
        Employee::create([
            'person_no' => 'EMP-001',
            'name' => 'Real Employee',
            'is_active' => true,
        ]);

        // Create dummy employee
        Employee::create([
            'person_no' => 'USR-1001',
            'name' => 'Dummy Employee',
            'is_active' => true,
        ]);

        $audit = $this->normalization->auditReal96Normalization();

        $this->assertEquals(2, $audit['total_employees']);
        $this->assertEquals(1, $audit['real_employees']);
        $this->assertEquals(1, $audit['dummy_employees']);
        $this->assertContains('USR-1001', $audit['dummy_ids_found']);
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
     * Test Building B audit maintains invariant.
     */
    public function test_building_b_audit_maintains_invariant(): void
    {
        Building::firstOrCreate(
            ['code' => 'BUILDING_B'],
            ['name' => 'Building B', 'is_active' => true]
        );

        $audit = $this->normalization->auditBuildingBEntitlement();

        $this->assertTrue($audit['invariant_valid']);
        $this->assertEquals(
            $audit['eligible_count'],
            $audit['assigned_count'] + $audit['missing_count']
        );
    }

    /**
     * Test write operations require authorization.
     */
    public function test_write_operations_require_authorization(): void
    {
        $result = $this->normalization->normalizeReal96(false);
        
        $this->assertEquals('NOT_AUTHORIZED', $result['status']);
        $this->assertEquals(0, $result['dummy_employees_deleted']);
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
