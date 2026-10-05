<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\BiometricStatus;
use App\Models\DevicePersonLink;
use App\Models\DevicePersonState;
use App\Models\Door;
use App\Models\DoorAssignment;
use App\Models\Employee;
use App\Jobs\SyncDoorAccessJob;
use App\Services\DeviceCredentialReconciliationService as Recon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Employee ↔ credential ↔ device reconciliation. Devices are only read (UserInfo and
 * CardInfo searches) and employees are never written by the reconciliation.
 */
class DeviceCredentialReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private Admin $superAdmin;
    private Door $doorA;
    private Door $doorB;
    /** @var array<string, array{users: array, cards: array}|string> host => device data or 'offline' */
    private array $devices = [];
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.hikvision.use_mock', false);
        Config::set('services.hikvision.username', 'admin');
        Config::set('services.hikvision.password', 'secret');
        $this->superAdmin = Admin::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($this->superAdmin);
        $this->doorA = Door::create(['door_id' => 'DOOR-A', 'door_name' => 'Door A', 'location' => 'Gedung A', 'device_ip' => '10.0.0.11']);
        $this->doorB = Door::create(['door_id' => 'DOOR-B', 'door_name' => 'Door B', 'location' => 'Gedung B', 'device_ip' => '10.0.0.12']);
        $this->fakeDevices();
    }

    // ---- fake ISAPI -------------------------------------------------------------------

    private function device(Door $door, array $users, array $cards = []): void
    {
        $this->devices[$door->device_ip] = ['users' => $users, 'cards' => $cards];
    }

    private function offline(Door $door): void
    {
        $this->devices[$door->device_ip] = 'offline';
    }

    private function fakeDevices(): void
    {
        Http::fake(function (HttpRequest $request) {
            $this->sent[] = [$request->method(), $request->url()];
            $host = parse_url($request->url(), PHP_URL_HOST);
            $device = $this->devices[$host] ?? ['users' => [], 'cards' => []];
            if ($device === 'offline') {
                throw new ConnectionException('Connection timed out');
            }
            if (str_contains($request->url(), '/AccessControl/UserInfo/Search')) {
                return Http::response(['UserInfoSearch' => [
                    'responseStatusStrg' => 'OK', 'numOfMatches' => count($device['users']), 'totalMatches' => count($device['users']),
                    'UserInfo' => $device['users'],
                ]]);
            }
            if (str_contains($request->url(), '/AccessControl/CardInfo/Search')) {
                return Http::response(['CardInfoSearch' => [
                    'responseStatusStrg' => 'OK', 'numOfMatches' => count($device['cards']), 'totalMatches' => count($device['cards']),
                    'CardInfo' => $device['cards'],
                ]]);
            }

            return Http::response([], 404);
        });
    }

    private function user(string $no, string $name, int $cards = 0, ?int $fp = 0): array
    {
        return array_filter(['employeeNo' => $no, 'name' => $name, 'Valid' => ['enable' => true], 'numOfCard' => $cards, 'numOfFP' => $fp, 'numOfFace' => 0], fn ($v) => $v !== null);
    }

    private function reconcile(array $doorIds = []): void
    {
        app(Recon::class)->run($this->superAdmin, $doorIds);
    }

    private function state(Door $door, string $no): DevicePersonState
    {
        return DevicePersonState::where('door_id', $door->id)->where('device_employee_no', $no)->firstOrFail();
    }

    private function employee(array $attributes = []): Employee
    {
        return Employee::factory()->create($attributes + ['source_person_number' => null]);
    }

    private function snapshotEmployees(): array
    {
        return Employee::withTrashed()->orderBy('id')->get()->map->getRawOriginal()->all();
    }

    // ---- 1–5: matching priority -------------------------------------------------------

    public function test_01_exact_device_person_number_matches(): void
    {
        $emp = $this->employee(['name' => 'Budi Santoso', 'source_person_number' => '1001', 'hikvision_employee_no' => 'X-1']);
        $this->device($this->doorA, [$this->user('1001', 'Budi Santoso', 0, 1)]);

        $this->reconcile();

        $state = $this->state($this->doorA, '1001');
        $this->assertSame([Recon::SYNC_SYNCED, Recon::LINK_MATCHED, Recon::IDENTITY_VERIFIED, $emp->id, 'person_number', 'high'], [$state->status, $state->device_link_status, $state->identity_status, $state->employee_id, $state->match_basis, $state->confidence]);
    }

    public function test_02_normalized_employee_id_is_review_candidate_only(): void
    {
        $emp = $this->employee(['employee_id' => '00042', 'hikvision_employee_no' => null, 'name' => 'Sari Dewi']);
        $this->device($this->doorA, [$this->user('42', 'SARI DEWI')]);

        $this->reconcile();

        $state = $this->state($this->doorA, '42');
        $this->assertSame([Recon::SYNC_REVIEW, Recon::LINK_DEVICE_ONLY, null, $emp->id, 'normalized_identifier_candidate'], [$state->status, $state->device_link_status, $state->employee_id, $state->candidate_employee_id, $state->match_basis]);
    }

    public function test_03_card_only_match_is_kept_for_review(): void
    {
        $emp = $this->employee(['name' => 'Kartu Saja', 'card_no' => '12345678']);
        $this->device($this->doorA, [$this->user('D-77', 'Kartu Saja', 1)], [['employeeNo' => 'D-77', 'cardNo' => '12345678', 'cardType' => 'normalCard']]);

        $this->reconcile();

        $state = $this->state($this->doorA, 'D-77');
        // Low-confidence match: linked, but the match itself needs review.
        $this->assertSame([Recon::SYNC_REVIEW, Recon::LINK_MATCHED, Recon::IDENTITY_REVIEW, $emp->id, 'card', 'medium', 'MATCHED'], [$state->status, $state->device_link_status, $state->identity_status, $state->employee_id, $state->match_basis, $state->confidence, $state->card_status]);
        $this->assertContains('matched_by_card_only', $state->reasons);
    }

    public function test_04_manual_link_is_used_as_mapping(): void
    {
        $emp = $this->employee(['name' => 'Andi Wijaya']);
        DevicePersonLink::create(['door_id' => $this->doorA->id, 'device_employee_no' => 'LEGACY-9', 'employee_id' => $emp->id, 'decision' => 'LINKED']);
        $this->device($this->doorA, [$this->user('LEGACY-9', 'Andi Wijaya')]);

        $this->reconcile();

        $state = $this->state($this->doorA, 'LEGACY-9');
        $this->assertSame([Recon::SYNC_SYNCED, Recon::LINK_MATCHED, $emp->id, 'manual_link'], [$state->status, $state->device_link_status, $state->employee_id, $state->match_basis]);
    }

    public function test_05_name_alone_never_links(): void
    {
        $emp = $this->employee(['name' => 'Rina Kusuma']);
        $this->device($this->doorA, [$this->user('UNKNOWN-5', 'Rina Kusuma')]);

        $this->reconcile();

        $state = $this->state($this->doorA, 'UNKNOWN-5');
        $this->assertSame([Recon::SYNC_DEVICE_ONLY, Recon::LINK_DEVICE_ONLY, null, $emp->id, 'low'], [$state->status, $state->device_link_status, $state->employee_id, $state->candidate_employee_id, $state->confidence]);
    }

    // ---- 6–8: review / conflict --------------------------------------------------------

    public function test_06_device_name_dash_needs_review(): void
    {
        $this->employee(['source_person_number' => '2001', 'name' => 'Siti']);
        $this->device($this->doorA, [$this->user('2001', '-'), $this->user('2002', '-')]);

        $this->reconcile();

        $matched = $this->state($this->doorA, '2001');
        $this->assertSame([Recon::LINK_MATCHED, Recon::IDENTITY_REVIEW, Recon::SYNC_PARTIAL], [$matched->device_link_status, $matched->identity_status, $matched->status]);
        $this->assertSame(Recon::SYNC_REVIEW, $this->state($this->doorA, '2002')->status, 'Unmatched person with no name is not silently device-only');
    }

    public function test_07_unverified_nik_needs_review(): void
    {
        $this->employee(['source_person_number' => '3001', 'name' => 'Joko', 'nik' => 'UNVERIFIED-NIK-3001']);
        $this->device($this->doorA, [$this->user('3001', 'Joko')]);

        $this->reconcile();

        $state = $this->state($this->doorA, '3001');
        $this->assertSame([Recon::LINK_MATCHED, Recon::IDENTITY_UNVERIFIED, Recon::SYNC_PARTIAL], [$state->device_link_status, $state->identity_status, $state->status]);
        $this->assertContains('nik_unverified', $this->state($this->doorA, '3001')->reasons);
    }

    public function test_08_higher_priority_exact_raw_identifier_wins(): void
    {
        $a = $this->employee(['source_person_number' => '4001', 'name' => 'Pegawai A']);
        $b = $this->employee(['employee_id' => '4001', 'hikvision_employee_no' => null, 'name' => 'Pegawai B']);
        $this->device($this->doorA, [$this->user('4001', 'Pegawai A')]);

        $this->reconcile();

        $state = $this->state($this->doorA, '4001');
        $this->assertSame([Recon::LINK_MATCHED, $a->id, 'person_number', 'high'], [$state->device_link_status, $state->employee_id, $state->match_basis, $state->confidence]);
        $this->assertNotSame($b->id, $state->employee_id);
    }

    public function test_exact_device_evidence_reconciles_existing_assignments_only(): void
    {
        Queue::fake();
        $pending = $this->employee(['source_person_number' => '7001', 'name' => 'Pending Exact']);
        $failed = $this->employee(['employee_id' => '7002', 'hikvision_employee_no' => null, 'name' => 'Failed Exact']);
        $appOnly = $this->employee(['source_person_number' => '7003', 'name' => 'App Only']);
        $nameOnly = $this->employee(['name' => 'Name Candidate']);
        $conflictA = $this->employee(['source_person_number' => '7005', 'name' => 'Conflict A']);
        $conflictB = $this->employee(['source_person_number' => '7005', 'name' => 'Conflict B']);

        $pendingAssignment = DoorAssignment::create(['employee_id' => $pending->id, 'door_id' => $this->doorA->id, 'sync_status' => 'pending']);
        $failedAssignment = DoorAssignment::create([
            'employee_id' => $failed->id, 'door_id' => $this->doorA->id, 'sync_status' => 'failed',
            'sync_attempts' => 3, 'last_sync_error' => 'previous write failed',
        ]);
        $appOnlyAssignment = DoorAssignment::create(['employee_id' => $appOnly->id, 'door_id' => $this->doorA->id, 'sync_status' => 'pending']);
        $nameOnlyAssignment = DoorAssignment::create(['employee_id' => $nameOnly->id, 'door_id' => $this->doorA->id, 'sync_status' => 'pending']);
        $conflictAssignment = DoorAssignment::create(['employee_id' => $conflictA->id, 'door_id' => $this->doorA->id, 'sync_status' => 'pending']);

        $this->device($this->doorA, [
            $this->user('7001', 'Pending Exact'),
            $this->user('7002', 'Failed Exact'),
            $this->user('DEVICE-ONLY', 'Unknown Person'),
            $this->user('NAME-ONLY', 'Name Candidate'),
            $this->user('7005', 'Conflict A'),
        ]);

        $this->reconcile([$this->doorA->id]);

        $this->assertSame(['synced', null, null], [$pendingAssignment->fresh()->sync_status, $pendingAssignment->fresh()->last_sync_error, $pendingAssignment->fresh()->last_synced_at]);
        $this->assertSame(['synced', 3, null, null], [$failedAssignment->fresh()->sync_status, $failedAssignment->fresh()->sync_attempts, $failedAssignment->fresh()->last_sync_error, $failedAssignment->fresh()->last_synced_at]);
        $this->assertSame('pending', $appOnlyAssignment->fresh()->sync_status);
        $this->assertSame('pending', $nameOnlyAssignment->fresh()->sync_status);
        $this->assertSame('pending', $conflictAssignment->fresh()->sync_status);
        $this->assertSame(5, DoorAssignment::count(), 'Device-only observations never create assignments');
        $this->assertSame([Recon::LINK_CONFLICT, null], [$this->state($this->doorA, '7005')->device_link_status, $this->state($this->doorA, '7005')->employee_id]);
        $this->assertSame([Recon::LINK_DEVICE_ONLY, null, $nameOnly->id], [$this->state($this->doorA, 'NAME-ONLY')->device_link_status, $this->state($this->doorA, 'NAME-ONLY')->employee_id, $this->state($this->doorA, 'NAME-ONLY')->candidate_employee_id]);
        $this->assertSame(2, ActivityLog::where('action', 'assignment_reconciled_from_device')->count());
        $this->assertStringContainsString('read-only device reconciliation', ActivityLog::where('action', 'assignment_reconciled_from_device')->firstOrFail()->description);
        Queue::assertNotPushed(SyncDoorAccessJob::class);
        foreach ($this->sent as [$method, $url]) {
            $this->assertSame('POST', $method);
            $this->assertMatchesRegularExpression('#/ISAPI/AccessControl/(UserInfo|CardInfo)/Search#', $url);
        }

        $this->reconcile([$this->doorA->id]);

        $this->assertSame(2, ActivityLog::where('action', 'assignment_reconciled_from_device')->count(), 'Rerun does not duplicate reconciliation audits');
        Queue::assertNotPushed(SyncDoorAccessJob::class);
    }

    // ---- 9–11: card & fingerprint ------------------------------------------------------

    public function test_09_different_card_is_card_mismatch(): void
    {
        $this->employee(['source_person_number' => '5001', 'name' => 'Dina', 'card_no' => '11112222']);
        $this->device($this->doorA, [$this->user('5001', 'Dina', 1)], [['employeeNo' => '5001', 'cardNo' => '99998888']]);

        $this->reconcile();

        $state = $this->state($this->doorA, '5001');
        $this->assertSame([Recon::SYNC_PARTIAL, Recon::LINK_MATCHED, Recon::IDENTITY_VERIFIED, 'MISMATCH'], [$state->status, $state->device_link_status, $state->identity_status, $state->card_status]);
        $this->assertContains('card_mismatch', $state->reasons);
        $this->assertSame(['****8888'], $state->card_masks);
    }

    public function test_10_app_fingerprint_without_device_fingerprint_is_biometric_mismatch(): void
    {
        $emp = $this->employee(['source_person_number' => '6001', 'name' => 'Eko']);
        BiometricStatus::create(['employee_id' => $emp->id, 'has_fingerprint' => true, 'card_enrolled' => false]);
        $this->device($this->doorA, [$this->user('6001', 'Eko', 0, 0)]);

        $this->reconcile();

        $state = $this->state($this->doorA, '6001');
        $this->assertSame([Recon::SYNC_PARTIAL, Recon::LINK_MATCHED, 'CONFLICT'], [$state->status, $state->device_link_status, $state->fingerprint_status]);
        $this->assertContains('fingerprint_conflict', $state->reasons);
    }

    public function test_11_fingerprint_status_comes_from_the_device(): void
    {
        $emp = $this->employee(['source_person_number' => '7001', 'name' => 'Fajar']);
        $emp->doors()->attach($this->doorA->id, ['sync_status' => 'synced']);
        $this->device($this->doorA, [$this->user('7001', 'Fajar', 0, 2)]);

        $this->reconcile();

        $this->assertSame('ENROLLED_ON_DEVICE', $this->state($this->doorA, '7001')->fingerprint_status);
        $verification = app(Recon::class)->employeeVerification($emp->fresh());
        $this->assertSame(['ENROLLED_ON_DEVICE', 'SYNCED'], [$verification['fingerprint_status'], $verification['sync_status']]);
        $this->assertFalse($verification['app_recorded']['fingerprint']);
    }

    // ---- 12–13: app-only and unreachable -----------------------------------------------

    public function test_12_assigned_employee_missing_from_device_is_app_only(): void
    {
        $emp = $this->employee(['source_person_number' => '8001', 'name' => 'Gita']);
        $emp->doors()->attach($this->doorA->id, ['sync_status' => 'synced']);
        $this->device($this->doorA, []);

        $this->reconcile();

        $verification = app(Recon::class)->employeeVerification($emp->fresh());
        $this->assertSame(['APP_ONLY', 'NOT_GRANTED'], [$verification['sync_status'], $verification['doors'][0]['access_status']]);
        $summary = collect(app(Recon::class)->deviceSummaries())->firstWhere('door_code', 'DOOR-A');
        $this->assertSame(1, $summary['app_only']);
    }

    public function test_13_unreachable_device_is_never_reported_as_not_enrolled(): void
    {
        $emp = $this->employee(['source_person_number' => '9001', 'name' => 'Hana']);
        $emp->doors()->attach($this->doorA->id, ['sync_status' => 'synced']);
        $this->device($this->doorA, [$this->user('9001', 'Hana', 0, 1)]);
        $this->reconcile();

        $this->offline($this->doorA);
        $this->reconcile();

        $verification = app(Recon::class)->employeeVerification($emp->fresh());
        $this->assertSame(['DEVICE_UNREACHABLE', 'DEVICE_UNREACHABLE', 'DEVICE_UNREACHABLE'], [$verification['sync_status'], $verification['fingerprint_status'], $verification['doors'][0]['access_status']]);
        $this->assertTrue($this->state($this->doorA, '9001')->present_on_device, 'Last known observation is kept');
        $this->assertSame('DEVICE_UNREACHABLE', collect(app(Recon::class)->deviceSummaries())->firstWhere('door_code', 'DOOR-A')['status']);
    }

    // ---- 14–18: safety -----------------------------------------------------------------

    public function test_14_runs_are_idempotent_and_never_write_employees(): void
    {
        $this->employee(['source_person_number' => '1101', 'name' => 'Indra', 'card_no' => '5555']);
        $this->employee(['name' => 'Tanpa Perangkat']);
        $this->device($this->doorA, [$this->user('1101', 'Indra', 1), $this->user('X-1', 'Orang Lain')], [['employeeNo' => '1101', 'cardNo' => '5555']]);
        $before = $this->snapshotEmployees();

        $this->reconcile();
        $first = DevicePersonState::orderBy('id')->get(['door_id', 'device_employee_no', 'status', 'employee_id', 'card_status'])->toArray();
        $this->reconcile();
        $second = DevicePersonState::orderBy('id')->get(['door_id', 'device_employee_no', 'status', 'employee_id', 'card_status'])->toArray();

        $this->assertSame($first, $second);
        $this->assertSame(2, DevicePersonState::count());
        $this->assertSame($before, $this->snapshotEmployees(), 'Reconciliation never creates or edits employees');
    }

    public function test_15_every_run_and_decision_is_audited(): void
    {
        $this->device($this->doorA, [$this->user('A-1', 'Orang Baru')]);
        $this->postJson('/api/v1/access/reconciliation/run')->assertOk();
        $this->assertSame(1, ActivityLog::where('action', 'device_reconciliation_run')->count());

        $state = $this->state($this->doorA, 'A-1');
        $this->postJson("/api/v1/access/reconciliation/device-persons/{$state->id}/decision", ['decision' => 'REVIEW', 'note' => 'cek HR'])->assertOk();
        $this->assertSame(1, ActivityLog::where('action', 'device_person_review')->where('subject_id', $state->id)->count());
    }

    public function test_16_devices_only_receive_read_requests(): void
    {
        $this->employee(['source_person_number' => '1201', 'name' => 'Joni']);
        $this->device($this->doorA, [$this->user('1201', 'Joni', 1)], [['employeeNo' => '1201', 'cardNo' => '777']]);
        $this->device($this->doorB, [$this->user('B-9', '-')]);

        $this->postJson('/api/v1/access/reconciliation/run')->assertOk();
        $state = $this->state($this->doorB, 'B-9');
        $this->postJson("/api/v1/access/reconciliation/device-persons/{$state->id}/decision", ['decision' => 'IGNORED'])->assertOk();

        $this->assertNotEmpty($this->sent);
        foreach ($this->sent as [$method, $url]) {
            $this->assertSame('POST', $method);
            $this->assertMatchesRegularExpression('#/ISAPI/AccessControl/(UserInfo|CardInfo)/Search#', $url, 'Only search endpoints may be called');
        }
    }

    public function test_17_card_numbers_are_never_stored_or_returned_in_plaintext(): void
    {
        $emp = $this->employee(['source_person_number' => '1301', 'name' => 'Kiki', 'card_no' => '0099887766']);
        $this->device($this->doorA, [$this->user('1301', 'Kiki', 1), $this->user('1302', 'Lala', 1)], [
            ['employeeNo' => '1301', 'cardNo' => '0099887766'],
            ['employeeNo' => '1302', 'cardNo' => '4444333322'],
        ]);
        $this->reconcile();

        $stored = json_encode(DevicePersonState::all()->map->getRawOriginal());
        $this->assertStringNotContainsString('4444333322', $stored);
        $this->assertStringNotContainsString('0099887766', $stored);

        $responses = $this->getJson('/api/v1/access/reconciliation')->assertOk()->getContent()
            . $this->getJson("/api/v1/access/reconciliation/employees/{$emp->id}")->assertOk()->getContent()
            . $this->getJson('/api/v1/access/reconciliation/device-persons/' . $this->state($this->doorA, '1302')->id)->assertOk()->getContent();
        $this->assertStringNotContainsString('4444333322', $responses);
        $this->assertStringNotContainsString('0099887766', $responses);
        $this->assertStringNotContainsString('card_hashes', $responses);
        $this->assertStringContainsString('****3322', $responses);
    }

    public function test_18_decisions_are_super_admin_only_and_never_duplicate_a_link(): void
    {
        $emp = $this->employee(['source_person_number' => '1401', 'name' => 'Maya']);
        $this->device($this->doorA, [$this->user('1401', 'Maya'), $this->user('OLD-1401', 'Maya Lama')]);
        $this->reconcile();
        $orphan = $this->state($this->doorA, 'OLD-1401');
        $employeeCount = Employee::count();

        $this->postJson("/api/v1/access/reconciliation/device-persons/{$orphan->id}/decision", ['decision' => 'LINKED', 'employee_id' => $emp->id])
            ->assertStatus(422);
        $this->assertNull($orphan->fresh()->employee_id);

        foreach (['building_admin', 'hrd', 'infra_admin'] as $role) {
            Sanctum::actingAs(Admin::factory()->create(['role' => $role]));
            $this->getJson('/api/v1/access/reconciliation')->assertForbidden();
            $this->postJson('/api/v1/access/reconciliation/run')->assertForbidden();
            $this->postJson("/api/v1/access/reconciliation/device-persons/{$orphan->id}/decision", ['decision' => 'IGNORED'])->assertForbidden();
        }

        Sanctum::actingAs($this->superAdmin);
        $other = $this->employee(['name' => 'Maya Lama']);
        $this->postJson("/api/v1/access/reconciliation/device-persons/{$orphan->id}/decision", ['decision' => 'LINKED', 'employee_id' => $other->id])->assertOk();
        $this->assertSame([Recon::SYNC_SYNCED, Recon::LINK_MATCHED, $other->id, 'manual_link'], [$orphan->fresh()->status, $orphan->fresh()->device_link_status, $orphan->fresh()->employee_id, $orphan->fresh()->match_basis]);
        $this->assertSame($employeeCount + 1, Employee::count(), 'Only the employee created by this test exists; linking creates none');
    }

    public function test_overview_tabs_and_unlinked_list(): void
    {
        $synced = $this->employee(['source_person_number' => '1501', 'name' => 'Nina']);
        $synced->doors()->attach($this->doorA->id, ['sync_status' => 'synced']);
        $appOnly = $this->employee(['source_person_number' => '1502', 'name' => 'Oki']);
        $appOnly->doors()->attach($this->doorA->id, ['sync_status' => 'synced']);
        $this->device($this->doorA, [$this->user('1501', 'Nina'), $this->user('Z-1', 'Asing')]);
        $this->reconcile([$this->doorA->id]);

        $data = $this->getJson('/api/v1/access/reconciliation')->assertOk()->json('data');
        $this->assertSame(1, $data['counts']['synced']);
        $this->assertSame(1, $data['counts']['app_only']);
        $this->assertSame(1, $data['counts']['device_only']);
        $this->assertSame(['Z-1'], array_column($data['unlinked'], 'person_number'));

        $deviceOnly = $this->getJson('/api/v1/access/reconciliation?tab=device_only')->assertOk()->json('data.rows');
        $this->assertSame(['device'], array_column($deviceOnly, 'kind'));
        $search = $this->getJson('/api/v1/access/reconciliation?search=oki')->assertOk()->json('data.rows');
        $this->assertSame([$appOnly->id], array_column($search, 'employee_id'));
        $this->getJson('/api/v1/access/reconciliation?tab=bogus')->assertStatus(422);
    }

    // ---- 19–28: employee update reactivity & truthfulness ------------------------------

    public function test_19_update_persists_app_flags_for_employees_without_biometric_row(): void
    {
        $emp = $this->employee(['name' => 'Imported']);
        $this->assertNull($emp->biometricStatus);

        $res = $this->putJson("/api/v1/user-management/employees/{$emp->id}", ['name' => 'Imported Updated', 'fingerprint_enrolled' => true, 'card_enrolled' => true])->assertOk();

        $this->assertTrue($emp->fresh()->biometricStatus->fingerprint_enrolled);
        $this->assertTrue($emp->fresh()->biometricStatus->card_enrolled);
        $this->assertSame('Imported Updated', $res->json('data.name'));
        $this->assertSame(['fingerprint_enrolled' => true, 'card_enrolled' => true], $res->json('data.biometric_status'));
    }

    public function test_20_update_can_clear_flags_and_keeps_one_row(): void
    {
        $emp = $this->employee();
        BiometricStatus::create(['employee_id' => $emp->id, 'has_fingerprint' => true, 'card_enrolled' => true]);

        $this->putJson("/api/v1/user-management/employees/{$emp->id}", ['fingerprint_enrolled' => false, 'card_enrolled' => false])->assertOk();

        $this->assertSame(1, BiometricStatus::where('employee_id', $emp->id)->count());
        $this->assertFalse($emp->fresh()->biometricStatus->fingerprint_enrolled);
        $this->assertFalse($emp->fresh()->biometricStatus->card_enrolled);
    }

    public function test_21_update_without_flags_does_not_invent_a_biometric_row(): void
    {
        $emp = $this->employee();
        $this->putJson("/api/v1/user-management/employees/{$emp->id}", ['name' => 'Only Name'])->assertOk();
        $this->assertSame(0, BiometricStatus::where('employee_id', $emp->id)->count());
    }

    public function test_22_checkbox_never_becomes_device_enrollment(): void
    {
        $emp = $this->employee(['source_person_number' => '2201', 'name' => 'Putri']);
        $emp->doors()->attach($this->doorA->id, ['sync_status' => 'synced']);

        $data = $this->putJson("/api/v1/user-management/employees/{$emp->id}", ['fingerprint_enrolled' => true, 'card_enrolled' => true])->assertOk()->json('data');

        $this->assertSame('UNKNOWN', $data['device_verification']['fingerprint_status']);
        // No device has been read: the card stays UNKNOWN even though the app records one.
        $this->assertSame('UNKNOWN', $data['device_verification']['card_status']);
        $this->assertSame('UNVERIFIED', $data['device_verification']['sync_status']);
        $this->assertSame(['card' => true, 'fingerprint' => true], $data['device_verification']['app_recorded']);
    }

    public function test_23_list_reports_device_truth_and_boolean_card_flag(): void
    {
        $emp = $this->employee(['source_person_number' => '2301', 'name' => 'Rudi', 'card_registered' => true]);
        $emp->doors()->attach($this->doorA->id, ['sync_status' => 'synced']);
        $this->device($this->doorA, [$this->user('2301', 'Rudi', 1, 1)]);
        $this->reconcile();

        $row = collect($this->getJson('/api/v1/user-management/employees?per_page=20')->assertOk()->json('data'))->firstWhere('id', $emp->id);
        $this->assertTrue($row['card_registered']);
        $this->assertSame(['DEVICE_FOUND', 'ENROLLED_ON_DEVICE', 'SYNCED'], [
            $row['device_verification']['card_status'], $row['device_verification']['fingerprint_status'], $row['device_verification']['sync_status'],
        ]);
    }

    private function script(): string
    {
        return file_get_contents(public_path('js/dashboard.js'));
    }

    private function bladeSource(): string
    {
        return file_get_contents(resource_path('views/dashboard.blade.php'));
    }

    private function functionBody(string $name): string
    {
        $script = $this->script();
        $this->assertSame(1, preg_match('/\n(?:async\s+)?function\s+' . preg_quote($name, '/') . '\s*\([^)]*\)\s*\{/', $script, $m, PREG_OFFSET_CAPTURE), "function {$name}() is not defined");
        $offset = $m[0][1];
        $next = preg_match('/\n(?:async\s+)?function\s+\w+\s*\(/', $script, $n, PREG_OFFSET_CAPTURE, $offset + 1) ? $n[0][1] : strlen($script);

        return substr($script, $offset, $next - $offset);
    }

    public function test_24_renderer_uses_device_verification_not_the_yes_string(): void
    {
        $this->assertStringNotContainsString("=== 'YES'", $this->script());
        $this->assertStringNotContainsString('FP Unknown', $this->script());
        $this->assertStringNotContainsString('Card Unknown', $this->script());
        $this->assertStringContainsString('employeeCredentialBadges(emp)', $this->functionBody('renderEmployeesTable'));
        $badges = $this->functionBody('employeeCredentialBadges');
        $this->assertStringContainsString('const verified = Boolean(verification?.last_verified_at);', $badges);
        $this->assertStringContainsString("verified && DEVICE_CARD_BADGES[verification.card_status] ? verification.card_status : 'UNKNOWN'", $badges);
        $this->assertStringContainsString("verified && DEVICE_FP_BADGES[verification.fingerprint_status] ? verification.fingerprint_status : 'UNKNOWN'", $badges);
        $this->assertStringContainsString('`Tercatat di aplikasi: ${recorded.join(\', \')}`', $badges);
    }

    public function test_25_save_patches_the_row_then_reloads_once_with_filters(): void
    {
        $save = $this->functionBody('saveEmployee');
        $this->assertStringContainsString('if (id) patchEmployeeRow(res.data);', $save);
        $this->assertSame(1, substr_count($save, 'loadEmployees('));
        $this->assertStringNotContainsString('location.reload', $save);
        $this->assertStringContainsString('closeModal(\'employeeModal\');', $save);
    }

    public function test_26_row_patch_rerenders_current_page_only(): void
    {
        $patch = $this->functionBody('patchEmployeeRow');
        $this->assertStringContainsString('state.employees[index] = updated;', $patch);
        $this->assertStringContainsString('renderEmployeesTable(state.employees);', $patch);
        $this->assertStringNotContainsString('apiFetch', $patch);
    }

    public function test_27_new_employee_form_does_not_preselect_enrollment(): void
    {
        $add = $this->functionBody('openAddEmployeeModal');
        $this->assertStringContainsString("document.getElementById('empFp').checked = false;", $add);
        $this->assertStringContainsString("document.getElementById('empCard').checked = false;", $add);
    }

    public function test_27b_editing_a_legacy_department_keeps_it_and_does_not_post_empty(): void
    {
        // 98/109 production-like employees have departments outside the fixed select list;
        // the select posted "" and every edit failed with 422 "department is required".
        $this->assertStringContainsString("setSelectValuePreserving(document.getElementById('empDept'), emp.department);", $this->functionBody('openEditEmployeeModal'));
        $this->assertStringContainsString("setSelectValuePreserving(document.getElementById('empDept'), '');", $this->functionBody('openAddEmployeeModal'));
        $helper = $this->functionBody('setSelectValuePreserving');
        $this->assertStringContainsString("option.dataset.preserved = '1';", $helper);
        $this->assertStringContainsString("select.querySelectorAll('option[data-preserved]').forEach(o => o.remove());", $helper);
        $this->assertStringContainsString("if (id && document.getElementById('empDept').selectedOptions[0]?.dataset.preserved) {", $this->functionBody('saveEmployee'));

        $emp = $this->employee(['department' => 'Belum Ditentukan']);
        $this->putJson("/api/v1/user-management/employees/{$emp->id}", ['name' => 'Tanpa Departemen Dikirim'])->assertOk();
        $this->assertSame(['Tanpa Departemen Dikirim', 'Belum Ditentukan'], [$emp->fresh()->name, $emp->fresh()->department]);
    }

    public function test_28_form_labels_say_app_recorded(): void
    {
        $blade = $this->bladeSource();
        $this->assertStringContainsString('<input type="checkbox" id="empFp"> Fingerprint tercatat di aplikasi', $blade);
        $this->assertStringContainsString('<input type="checkbox" id="empCard"> Card tercatat di aplikasi', $blade);
        $this->assertStringNotContainsString('Fingerprint Enrolled', $blade);
        $this->assertStringNotContainsString('Card Enrolled', $blade);
    }

    // ---- 29–34: Reconciliation Center UI contracts -------------------------------------

    public function test_29_section_is_hidden_and_super_admin_only(): void
    {
        $this->assertStringContainsString('<div id="deviceReconciliationSection" class="table-container" style="display: none;', $this->bladeSource());
        $this->assertStringContainsString("window.APP_CONFIG?.admin?.role === 'super_admin'", $this->functionBody('canUseDeviceReconciliation'));
        $this->assertStringContainsString('if (!canUseDeviceReconciliation()) return;', $this->functionBody('loadDeviceReconciliation'));
    }

    public function test_30_tabs_match_the_api(): void
    {
        foreach (['all' => 'Semua', 'synced' => 'Tersinkron', 'partial' => 'Partial', 'app_only' => 'Hanya Aplikasi', 'device_only' => 'Hanya Perangkat', 'conflict' => 'Konflik', 'review' => 'Perlu Verifikasi'] as $tab => $label) {
            $this->assertStringContainsString("data-recon-tab=\"{$tab}\" onclick=\"setReconciliationTab('{$tab}')\">{$label}</button>", $this->bladeSource());
        }
    }

    public function test_31_table_columns_and_unlinked_section(): void
    {
        $blade = $this->bladeSource();
        $this->assertStringContainsString('<th>Identitas</th><th>NIK / Person Number</th><th>Credential</th><th>Card</th><th>Fingerprint</th><th>Device</th><th>Identity Status</th><th>Device Link</th><th>Sync Status</th><th>Last Verified</th>', $blade);
        $this->assertStringContainsString('Data Perangkat Belum Terhubung', $blade);
        foreach (['Hubungkan ke Pengguna', 'Tandai Perlu Verifikasi', 'Abaikan', 'Refresh dari Perangkat'] as $action) {
            $this->assertStringContainsString($action, $blade);
        }
    }

    public function test_32_loaded_lazily_with_one_request_and_a_freshness_window(): void
    {
        $this->assertStringNotContainsString('loadDeviceReconciliation', $this->functionBody('loadAccessData'));
        $this->assertStringContainsString('loadDeviceReconciliation();', $this->functionBody('switchAccessSubTab'));
        $load = $this->functionBody('loadDeviceReconciliation');
        $this->assertSame(1, substr_count($load, 'apiFetch('));
        $this->assertStringContainsString('if (!force && Date.now() - reconState.loadedAt < RECON_FRESH_MS) return;', $load);
        $this->assertStringContainsString('if (seq !== reconState.seq', $load);
        $boot = substr($this->script(), strpos($this->script(), "document.addEventListener('DOMContentLoaded'"));
        $this->assertStringNotContainsString('loadDeviceReconciliation', substr($boot, 0, strpos($boot, "\n});") ?: strlen($boot)));
    }

    public function test_33_async_handlers_are_exposed_for_inline_calls(): void
    {
        $exports = substr($this->script(), strrpos($this->script(), 'Object.assign(window, {'));
        foreach (['loadDeviceReconciliation', 'openReconDevicePersonDetail', 'openReconEmployeeDetail', 'runDeviceReconciliation', 'submitReconciliationDecision'] as $fn) {
            $this->assertStringContainsString("    {$fn},", $exports);
        }
    }

    public function test_34_ui_has_no_bulk_or_destructive_device_action(): void
    {
        $run = $this->functionBody('runDeviceReconciliation');
        $this->assertStringContainsString("apiFetch('/access/reconciliation/run'", $run);
        $this->assertStringContainsString('if (!canUseDeviceReconciliation() || button?.disabled) return;', $run);
        foreach (['loadDeviceReconciliation', 'runDeviceReconciliation', 'submitReconciliationDecision', 'openReconDevicePersonDetail', 'openReconEmployeeDetail'] as $fn) {
            $body = $this->functionBody($fn);
            $this->assertStringNotContainsString("method: 'DELETE'", $body);
            $this->assertStringNotContainsString("method: 'PUT'", $body);
            $this->assertStringNotContainsString('/user-management/employees', $body);
        }
    }
}
