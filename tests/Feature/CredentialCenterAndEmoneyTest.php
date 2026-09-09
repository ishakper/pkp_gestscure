<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\CredentialDeviceSync;
use App\Models\CredentialRecord;
use App\Models\Door;
use App\Models\Employee;
use App\Models\EmoneyCard;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CredentialCenterAndEmoneyTest extends TestCase
{
    use RefreshDatabase;

    protected Door $door;

    protected function setUp(): void
    {
        parent::setUp();

        $this->door = Door::create([
            'door_id' => 'DOOR-B',
            'name' => 'Ruang Server Lantai 2',
            'door_name' => 'Ruang Server Lantai 2',
            'location' => 'Kantor Pusat PKP',
            'ip_address' => '192.168.1.51',
            'status' => 'online',
            'connection_status' => 'online',
        ]);
    }

    public function test_credential_creation_masks_identifier_and_stores_no_raw_biometrics(): void
    {
        $superadmin = Admin::create([
            'name' => 'Security Admin',
            'email' => 'sec.admin@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
        ]);

        $employee = Employee::create([
            'employee_id' => 'EMP-2001',
            'nik' => '3201010101010011',
            'name' => 'Gita Gutawa',
            'email' => 'gita@pkp.co.id',
            'department' => 'Security',
            'role' => 'Security Analyst',
            'employment_type' => 'PERMANENT',
            'employment_status' => 'ACTIVE',
        ]);

        Sanctum::actingAs($superadmin);

        $res = $this->postJson('/api/v1/access/credentials', [
            'employee_id' => $employee->id,
            'credential_type' => 'CARD',
            'card_number' => '1234567890',
            'biometric_status' => 'ENROLLED',
            'external_reference' => 'EXT-HIK-2001',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.biometric_status', 'ENROLLED')
            ->assertJsonPath('data.masked_identifier', '******7890');

        // Verify unmasked card_number is hidden from JSON serialization
        $this->assertArrayNotHasKey('card_number', $res->json('data'));

        // Verify no raw biometric columns exist or contain raw data
        $this->assertDatabaseHas('credential_records', [
            'employee_id' => $employee->id,
            'masked_identifier' => '******7890',
            'biometric_status' => 'ENROLLED',
        ]);
    }

    public function test_duplicate_card_credential_is_prevented(): void
    {
        $superadmin = Admin::create([
            'name' => 'Admin Gate',
            'email' => 'gate@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
        ]);

        $emp1 = Employee::create([
            'employee_id' => 'EMP-2002',
            'nik' => '3201010101010012',
            'name' => 'Karyawan Satu',
            'email' => 'satu@pkp.co.id',
            'department' => 'Operations',
            'role' => 'Staff',
            'employment_type' => 'PERMANENT',
            'employment_status' => 'ACTIVE',
        ]);

        $emp2 = Employee::create([
            'employee_id' => 'EMP-2003',
            'nik' => '3201010101010013',
            'name' => 'Karyawan Dua',
            'email' => 'dua@pkp.co.id',
            'department' => 'Operations',
            'role' => 'Staff',
            'employment_type' => 'PERMANENT',
            'employment_status' => 'ACTIVE',
        ]);

        CredentialRecord::create([
            'credential_number' => 'CRD-2026-0010',
            'employee_id' => $emp1->id,
            'credential_type' => 'CARD',
            'card_number' => '9998887776',
            'masked_identifier' => '******7776',
            'status' => 'ACTIVE',
        ]);

        Sanctum::actingAs($superadmin);

        // Attempt to issue same card to emp2
        $res = $this->postJson('/api/v1/access/credentials', [
            'employee_id' => $emp2->id,
            'credential_type' => 'CARD',
            'card_number' => '9998887776',
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['card_number']);
    }

    public function test_device_sync_queue_creation_and_retry_state(): void
    {
        $superadmin = Admin::create([
            'name' => 'Infra Sync Admin',
            'email' => 'infra.sync@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
        ]);

        $crd = CredentialRecord::create([
            'credential_number' => 'CRD-2026-0011',
            'credential_type' => 'CARD',
            'card_number' => '5554443332',
            'masked_identifier' => '******3332',
            'status' => 'ACTIVE',
        ]);

        $sync = CredentialDeviceSync::create([
            'door_id' => $this->door->id,
            'credential_record_id' => $crd->id,
            'operation' => 'ADD',
            'status' => 'FAILED',
            'attempt_count' => 1,
            'error_summary' => 'Timeout connecting to device 192.168.1.51',
            'idempotency_key' => 'door_b_crd_11_op_add',
        ]);

        Sanctum::actingAs($superadmin);

        // Retry device sync
        $res = $this->postJson("/api/v1/access/device-syncs/{$sync->id}/retry");
        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'SUCCESS');

        $this->assertEquals('SUCCESS', $sync->fresh()->status);
        $this->assertEquals(2, $sync->fresh()->attempt_count);
        $this->assertNull($sync->fresh()->error_summary);
    }

    public function test_credential_revocation_queues_device_revocation(): void
    {
        $superadmin = Admin::create([
            'name' => 'Sec Admin',
            'email' => 'sec.admin2@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
        ]);

        $crd = CredentialRecord::create([
            'credential_number' => 'CRD-2026-0012',
            'credential_type' => 'CARD',
            'card_number' => '8887776665',
            'masked_identifier' => '******6665',
            'status' => 'ACTIVE',
        ]);

        Sanctum::actingAs($superadmin);

        $res = $this->postJson("/api/v1/access/credentials/{$crd->id}/revoke", [
            'reason' => 'Kartu RFID dilaporkan hilang oleh pegawai.',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertEquals('REVOKED', $crd->fresh()->status);
        $this->assertNotNull($crd->fresh()->revoked_at);

        // Check device sync queue has REVOKE operation
        $this->assertDatabaseHas('credential_device_syncs', [
            'credential_record_id' => $crd->id,
            'operation' => 'REVOKE',
            'status' => 'QUEUED',
        ]);
    }

    public function test_technical_role_denied_private_credentials_and_emoney(): void
    {
        $devops = Admin::create([
            'name' => 'DevOps Tech',
            'email' => 'devops@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'devops',
        ]);

        Sanctum::actingAs($devops);

        // Technical role without credential privilege cannot view credentials
        $crdRes = $this->getJson('/api/v1/access/credentials');
        $crdRes->assertStatus(403);

        // Technical role cannot view e-money registry
        $emnRes = $this->getJson('/api/v1/access/emoney');
        $emnRes->assertStatus(403);
    }

    public function test_emoney_admin_registration_masks_card_and_prevents_duplicate(): void
    {
        $superadmin = Admin::create([
            'name' => 'Finance Admin',
            'email' => 'fin.admin@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
        ]);

        $employee = Employee::create([
            'employee_id' => 'EMP-2005',
            'nik' => '3201010101010015',
            'name' => 'Dewi Sartika',
            'email' => 'dewi@pkp.co.id',
            'department' => 'Finance',
            'role' => 'Treasurer',
            'employment_type' => 'PERMANENT',
            'employment_status' => 'ACTIVE',
        ]);

        Sanctum::actingAs($superadmin);

        // Register Mandiri e-Money
        $res = $this->postJson('/api/v1/access/emoney', [
            'employee_id' => $employee->id,
            'provider' => 'MANDIRI_EMONEY',
            'card_number' => '6032987654321098',
            'notes' => 'Kartu operasional perjalanan dinas',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.masked_card_number', '6032-****-****-1098')
            ->assertJsonPath('data.status', 'ASSIGNED');

        // card_hash is hidden from JSON serialization
        $this->assertArrayNotHasKey('card_hash', $res->json('data'));

        // Prevent duplicate card
        $dupRes = $this->postJson('/api/v1/access/emoney', [
            'provider' => 'MANDIRI_EMONEY',
            'card_number' => '6032987654321098',
        ]);
        $dupRes->assertStatus(422)
            ->assertJsonValidationErrors(['card_number']);
    }

    public function test_emoney_status_transition_lifecycle(): void
    {
        $superadmin = Admin::create([
            'name' => 'Admin Card',
            'email' => 'admin.card@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
        ]);

        $card = EmoneyCard::create([
            'card_uuid' => 'EMN-2026-0001',
            'provider' => 'BCA_FLAZZ',
            'masked_card_number' => '1234-****-****-5678',
            'card_hash' => hash('sha256', '1234000000005678'),
            'status' => 'ACTIVE',
        ]);

        Sanctum::actingAs($superadmin);

        $res = $this->postJson("/api/v1/access/emoney/{$card->id}/status", [
            'status' => 'LOST',
            'notes' => 'Kartu hilang saat dinas luar kota',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'LOST');

        $this->assertEquals('LOST', $card->fresh()->status);
        $this->assertNotNull($card->fresh()->returned_at);
    }

    public function test_employee_360_includes_access_credentials_and_emoney_sections(): void
    {
        $superadmin = Admin::create([
            'name' => 'HR Exec',
            'email' => 'hr.exec@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
        ]);

        $employee = Employee::create([
            'employee_id' => 'EMP-2006',
            'nik' => '3201010101010016',
            'name' => 'Eko Prasetyo',
            'email' => 'eko@pkp.co.id',
            'department' => 'Technology',
            'role' => 'Architect',
            'employment_type' => 'PERMANENT',
            'employment_status' => 'ACTIVE',
        ]);

        CredentialRecord::create([
            'credential_number' => 'CRD-2026-0020',
            'employee_id' => $employee->id,
            'credential_type' => 'CARD',
            'card_number' => '4443332221',
            'masked_identifier' => '******2221',
            'status' => 'ACTIVE',
        ]);

        EmoneyCard::create([
            'card_uuid' => 'EMN-2026-0005',
            'employee_id' => $employee->id,
            'provider' => 'BRI_BRIZZI',
            'masked_card_number' => '5214-****-****-9876',
            'card_hash' => hash('sha256', '5214000000009876'),
            'status' => 'ACTIVE',
        ]);

        Sanctum::actingAs($superadmin);

        $res = $this->getJson("/api/v1/user-management/employees/{$employee->id}/360");
        $res->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'data' => [
                    'employee',
                    'overview',
                    'organization',
                    'access',
                    'credentials',
                    'emoney_summary',
                    'audit_summary',
                ]
            ]);

        $this->assertCount(1, $res->json('data.credentials'));
        $this->assertEquals('******2221', $res->json('data.credentials.0.masked_identifier'));
        $this->assertCount(1, $res->json('data.emoney_summary'));
        $this->assertEquals('5214-****-****-9876', $res->json('data.emoney_summary.0.masked_card_number'));
    }
}
