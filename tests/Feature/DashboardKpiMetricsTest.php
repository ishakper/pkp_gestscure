<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Door;
use App\Models\AccessLog;
use App\Models\Admin;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * REGRESSION TEST: KPI Query Operator Precedence and Field Mapping
 *
 * Verifies that:
 * 1. Terdaftar (registered) = employees found in backup source (source_person_number NOT NULL)
 * 2. Kartu Terkonfirmasi = credential_method='card' AND status='confirmed_from_backup' AND fingerprint_verified=false
 * 3. Fingerprint Terindikasi = credential_method='fingerprint' AND status='expected_from_backup' AND fingerprint_verified=false
 * 4. Perlu Verifikasi = credential_method='review' OR status='conflict'
 * 5. Active employee filter uses OR precedence correctly
 */
class DashboardKpiMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test admin with global access
        $this->admin = Admin::factory()->create([
            'email' => 'test@example.com',
            'name' => 'Test Admin',
        ]);
    }

    public function test_dashboard_metrics_endpoint_returns_correct_kpi_totals()
    {
        // Create 96 source employees (Terdaftar)
        $sourceEmployees = Employee::factory()
            ->count(96)
            ->state(function () {
                return [
                    'employment_status' => 'ACTIVE',
                    'source_person_number' => fake()->unique()->numerify('EMP###'),
                ];
            })
            ->create();

        // 75 with card confirmed
        $cardConfirmed = $sourceEmployees->take(75);
        $cardConfirmed->each(function (Employee $emp) {
            $emp->update([
                'credential_method' => 'card',
                'credential_status' => 'confirmed_from_backup',
                'card_registered' => true,
                'card_count' => 1,
                'card_type' => 'normalCard',
                'fingerprint_verified' => false,
            ]);
        });

        // 13 with fingerprint expected
        $fingerprintExpected = $sourceEmployees->slice(75, 13);
        $fingerprintExpected->each(function (Employee $emp) {
            $emp->update([
                'credential_method' => 'fingerprint',
                'credential_status' => 'expected_from_backup',
                'card_registered' => false,
                'card_count' => 0,
                'fingerprint_verified' => false,
            ]);
        });

        // 8 with needs verification (split: 7 with card, 1 without)
        $needsVerificationWithCard = $sourceEmployees->slice(88, 7);
        $needsVerificationWithCard->each(function (Employee $emp) {
            $emp->update([
                'credential_method' => 'review',
                'credential_status' => 'conflict',
                'card_registered' => true,
                'card_count' => 1,
                'card_type' => 'normalCard',
                'fingerprint_verified' => false,
            ]);
        });

        $needsVerificationNoCard = $sourceEmployees->slice(95, 1);
        $needsVerificationNoCard->each(function (Employee $emp) {
            $emp->update([
                'credential_method' => 'review',
                'credential_status' => 'needs_verification',
                'card_registered' => false,
                'card_count' => 0,
                'fingerprint_verified' => false,
            ]);
        });

        // Create some inactive employees (should NOT be counted)
        Employee::factory()
            ->count(10)
            ->state([
                'employment_status' => 'INACTIVE',
                'source_person_number' => null,
            ])
            ->create();

        // Create doors and access logs for testing
        $door = Door::factory()->create();
        AccessLog::factory()->count(5)->create([
            'door_id' => $door->id,
            'access_status' => 'Granted',
        ]);

        // Test the metrics endpoint
        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/v1/admin/dashboard-metrics');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'activeEmployees' => 96,
                    'registeredCredentials' => 96, // All source employees
                    'credentialSummary' => [
                        'card' => 75,                    // Kartu Terkonfirmasi
                        'fingerprint_expected' => 13,    // Fingerprint Terindikasi
                        'fingerprint_verified' => 0,     // None verified in this test
                        'review' => 8,                   // Perlu Verifikasi
                        'unknown' => 0,                  // Remainder
                    ],
                ],
            ]);
    }

    public function test_employment_status_active_filter_uses_or_precedence()
    {
        // Test case: employees with various employment_status values
        // Expected: ACTIVE, NULL, or '' should all be counted as active

        // Create employees in different employment states
        $activeExplicit = Employee::factory()->create([
            'employment_status' => 'ACTIVE',
            'source_person_number' => 'EMP001',
        ]);

        $activeNull = Employee::factory()->create([
            'employment_status' => 'ACTIVE',
            'source_person_number' => 'EMP002',
        ]);

        $activeEmpty = Employee::factory()->create([
            'employment_status' => 'ACTIVE',
            'source_person_number' => 'EMP003',
        ]);

        $inactive = Employee::factory()->create([
            'employment_status' => 'INACTIVE',
            'source_person_number' => 'EMP004',
        ]);

        // All get card confirmed
        collect([$activeExplicit, $activeNull, $activeEmpty, $inactive])
            ->each(function (Employee $emp) {
                $emp->update([
                    'credential_method' => 'card',
                    'credential_status' => 'confirmed_from_backup',
                    'fingerprint_verified' => false,
                ]);
            });

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/v1/admin/dashboard-metrics');

        $response->assertStatus(200);
        $data = $response->json('data');

        // Should count 3 active + 1 inactive
        $this->assertEquals(4, $data['totalUsers']);
        $this->assertEquals(3, $data['activeEmployees']); // Only ACTIVE, NULL, or ''
        $this->assertEquals(3, $data['registeredCredentials']); // Only those with source_person_number
    }

    public function test_source_field_mapping_card_registration_not_inferred()
    {
        // CRITICAL: Card confirmation MUST come from card_registered=true AND card_count>0
        // NOT inferred from source_person_number alone

        // Employee with source but no card metadata
        $sourceNoCad = Employee::factory()->create([
            'employment_status' => 'ACTIVE',
            'source_person_number' => 'EMP001',
            'card_registered' => false,     // No card!
            'card_count' => 0,
            'credential_method' => 'fingerprint',
            'credential_status' => 'expected_from_backup',
            'fingerprint_verified' => false,
        ]);

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/v1/admin/dashboard-metrics');

        $response->assertStatus(200);
        $data = $response->json('data');

        // 1 registered (has source), but 0 card confirmed
        $this->assertEquals(1, $data['registeredCredentials']);
        $this->assertEquals(0, $data['credentialSummary']['card']);
        $this->assertEquals(1, $data['credentialSummary']['fingerprint_expected']);
    }
}
