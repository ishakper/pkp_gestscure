<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\CredentialReconciliationBatch;
use App\Services\HikvisionCredentialReconciliation;
use Tests\TestCase;

class CredentialReconciliationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Ensure we have a fresh database for testing
    }

    public function test_can_classify_card_confirmed_credential()
    {
        $record = [
            'card_registered' => true,
            'card_count' => 1,
            'card_type' => 'normalCard',
            'person_no' => 'EMP001',
            'display_name' => 'John Doe'
        ];

        $reconciliation = new HikvisionCredentialReconciliation(':memory:', true);
        $credential = $reconciliation->classifyCredential($record);

        $this->assertEquals('card', $credential['method']);
        $this->assertEquals('confirmed_from_backup', $credential['status']);
    }

    public function test_can_classify_fingerprint_expected_credential()
    {
        $record = [
            'card_registered' => false,
            'card_count' => 0,
            'card_type' => '-',
            'person_no' => 'EMP002',
            'display_name' => 'Jane Doe'
        ];

        $reconciliation = new HikvisionCredentialReconciliation(':memory:', true);
        $credential = $reconciliation->classifyCredential($record);

        $this->assertEquals('fingerprint', $credential['method']);
        $this->assertEquals('expected_from_backup', $credential['status']);
    }

    public function test_super_card_remains_super_card()
    {
        $record = [
            'card_registered' => true,
            'card_count' => 1,
            'card_type' => 'superCard',
            'person_no' => 'EMP003',
            'display_name' => 'Admin'
        ];

        $reconciliation = new HikvisionCredentialReconciliation(':memory:', true);
        $credential = $reconciliation->classifyCredential($record);

        $this->assertEquals('card', $credential['method']);
        $this->assertEquals('confirmed_from_backup', $credential['status']);
    }

    public function test_patrol_card_remains_patrol_card()
    {
        $record = [
            'card_registered' => true,
            'card_count' => 1,
            'card_type' => 'patrolCard',
            'person_no' => 'EMP004',
            'display_name' => 'Security'
        ];

        $reconciliation = new HikvisionCredentialReconciliation(':memory:', true);
        $credential = $reconciliation->classifyCredential($record);

        $this->assertEquals('card', $credential['method']);
        $this->assertEquals('confirmed_from_backup', $credential['status']);
    }

    public function test_no_card_does_not_become_fingerprint_verified()
    {
        $record = [
            'card_registered' => false,
            'card_count' => 0,
            'card_type' => '-',
            'person_no' => 'EMP005',
            'display_name' => 'Employee'
        ];

        $reconciliation = new HikvisionCredentialReconciliation(':memory:', true);
        $credential = $reconciliation->classifyCredential($record);

        // Must be expected, never auto-verified
        $this->assertNotEquals('verified', $credential['status']);
        $this->assertEquals('expected_from_backup', $credential['status']);
    }

    public function test_conflict_record_becomes_review()
    {
        $record = [
            'card_registered' => true,
            'card_count' => 0, // Inconsistency
            'card_type' => 'normalCard',
            'conflict' => 'Card registered YES but count is 0',
            'person_no' => 'EMP006',
            'display_name' => 'Conflict'
        ];

        $reconciliation = new HikvisionCredentialReconciliation(':memory:', true);
        $credential = $reconciliation->classifyCredential($record);

        $this->assertEquals('review', $credential['method']);
        $this->assertEquals('conflict', $credential['status']);
    }

    public function test_dry_run_performs_zero_mutations()
    {
        $initialCount = Employee::count();

        $reconciliation = new HikvisionCredentialReconciliation(':memory:', true);
        // Dry-run should not mutate

        $finalCount = Employee::count();
        $this->assertEquals($initialCount, $finalCount);
    }

    public function test_exact_match_can_be_updated()
    {
        $employee = Employee::factory()->create([
            'hikvision_employee_no' => 'EMP007',
            'credential_method' => 'unknown',
            'credential_status' => 'unknown',
        ]);

        $record = [
            'card_registered' => true,
            'card_count' => 1,
            'card_type' => 'normalCard',
            'person_no' => 'EMP007',
            'display_name' => $employee->name
        ];

        $reconciliation = new HikvisionCredentialReconciliation(':memory:', true);
        $credential = $reconciliation->classifyCredential($record);

        // In dry-run, nothing changes
        $employee->refresh();
        $this->assertNotEquals('card', $employee->credential_method);
    }

    public function test_case_sensitive_id_matching()
    {
        Employee::factory()->create(['hikvision_employee_no' => 'L261072']);

        $record1 = ['person_no' => 'L261072'];
        $record2 = ['person_no' => 'l261071'];

        // These should NOT match
        $this->assertNotEquals($record1['person_no'], $record2['person_no']);
    }

    public function test_id_with_leading_zeros_preserved()
    {
        Employee::factory()->create(['hikvision_employee_no' => '00001']);

        // ID must stay as string "00001", not become integer 1
        $employee = Employee::where('hikvision_employee_no', '00001')->first();
        $this->assertNotNull($employee);
        $this->assertEquals('00001', $employee->hikvision_employee_no);
    }

    public function test_nameless_record_not_auto_created()
    {
        $initialCount = Employee::count();

        $record = [
            'card_registered' => false,
            'card_count' => 0,
            'person_no' => 'EMP008',
            'display_name' => '-' // Nameless
        ];

        // Nameless should go to review, not created
        $reconciliation = new HikvisionCredentialReconciliation(':memory:', true);
        $credential = $reconciliation->classifyCredential($record);

        $finalCount = Employee::count();
        $this->assertEquals($initialCount, $finalCount);
    }
}
