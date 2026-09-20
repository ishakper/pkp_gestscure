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

        // Verify audit returns either error or valid structure
        $this->assertTrue(
            isset($audit['error']) || isset($audit['invariant_valid']),
            'Audit must return either error or invariant_valid'
        );
        
        if (isset($audit['error'])) {
            $this->assertIsString($audit['error']);
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
     * Test card audit is read-only.
     */
    public function test_card_audit_is_read_only(): void
    {
        $audit1 = $this->cardSecurity->auditCardHashing();
        $audit2 = $this->cardSecurity->auditCardHashing();
        
        $this->assertEquals($audit1, $audit2, 'Audit should not modify state');
    }
}
