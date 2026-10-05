<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\DevicePersonLink;
use App\Models\DevicePersonState;
use App\Models\Door;
use App\Models\Employee;
use App\Services\DeviceCredentialReconciliationService as Recon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Production read-only pilot on DOOR-B: 98 users, 82 cards. The first version reported
 * MATCHED=0 / REVIEW=93 because a placeholder NIK (UNVERIFIED-NIK-*) turned exact
 * person-number matches into REVIEW, and a fingerprint count the device never reported was
 * summed as 0. The fixture mirrors that device: 93 exact person numbers (one without a
 * name on the device), Vidi as distinct raw identifiers 00001 and 1, three device-only
 * persons (Endy with a name-only candidate), one assigned employee missing from the device,
 * and no numOfFP for anyone.
 */
class DoorBReconciliationRefinementTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;
    private Door $doorB;
    private array $users = [];
    private array $cards = [];
    private array $sent = [];
    private Employee $ami;
    private Employee $blankName;
    private Employee $vidiA;
    private Employee $vidiB;
    private Employee $endy;
    private Employee $appOnly;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.hikvision.use_mock', false);
        Config::set('services.hikvision.username', 'admin');
        Config::set('services.hikvision.password', 'secret');
        $this->admin = Admin::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($this->admin);
        $this->doorB = Door::create(['door_id' => 'DOOR-B', 'door_name' => 'Door B', 'location' => 'Gedung B', 'device_ip' => '10.0.0.15']);
        $this->buildDoorBFixture();

        Http::fake(function (HttpRequest $request) {
            $this->sent[] = [$request->method(), $request->url()];
            if (str_contains($request->url(), '/AccessControl/UserInfo/Search')) {
                return Http::response(['UserInfoSearch' => ['responseStatusStrg' => 'OK', 'numOfMatches' => count($this->users), 'totalMatches' => count($this->users), 'UserInfo' => $this->users]]);
            }
            if (str_contains($request->url(), '/AccessControl/CardInfo/Search')) {
                return Http::response(['CardInfoSearch' => ['responseStatusStrg' => 'OK', 'numOfMatches' => count($this->cards), 'totalMatches' => count($this->cards), 'CardInfo' => $this->cards]]);
            }

            return Http::response([], 404);
        });
    }

    private function person(string $no, string $name, int $cards): array
    {
        // DOOR-B reports numOfCard but no numOfFP / numOfFace.
        return ['employeeNo' => $no, 'name' => $name, 'Valid' => ['enable' => true], 'numOfCard' => $cards];
    }

    private function buildDoorBFixture(): void
    {
        $cardsLeft = 82;
        for ($i = 0; $i < 93; $i++) {
            $no = (string) (250600 + $i);
            $name = $no === '250611' ? 'Ami' : "Pegawai {$no}";
            $deviceName = $no === '250650' ? '' : $name; // one record without a name on the device
            $employee = Employee::factory()->create([
                'employee_id' => $no, 'hikvision_employee_no' => $no, 'source_person_number' => $no,
                'nik' => "UNVERIFIED-NIK-{$no}", 'name' => $name,
            ]);
            $employee->doors()->attach($this->doorB->id, ['sync_status' => 'synced']);
            $hasCard = $cardsLeft-- > 0;
            $this->users[] = $this->person($no, $deviceName, $hasCard ? 1 : 0);
            if ($hasCard) {
                $this->cards[] = ['employeeNo' => $no, 'cardNo' => (string) (900000 + $i), 'cardType' => 'normalCard'];
            }
            if ($no === '250611') {
                $this->ami = $employee;
            }
            if ($no === '250650') {
                $this->blankName = $employee;
            }
        }

        // Vidi: leading zeroes are significant raw identifier data.
        $this->vidiA = Employee::factory()->create(['employee_id' => '00001', 'hikvision_employee_no' => '00001', 'source_person_number' => '00001', 'nik' => 'UNVERIFIED-NIK-00001', 'name' => 'Vidi']);
        $this->vidiB = Employee::factory()->create(['employee_id' => '1', 'hikvision_employee_no' => '1', 'source_person_number' => null, 'nik' => 'UNVERIFIED-NIK-1', 'name' => 'Vidi Pratama']);
        $this->users[] = $this->person('00001', 'Vidi', 0);
        $this->users[] = $this->person('1', 'Vidi', 0);

        // Device-only; Endy has a same-name employee under a different number.
        $this->endy = Employee::factory()->create(['employee_id' => 'EMP-0085', 'hikvision_employee_no' => 'EMP-0085', 'source_person_number' => null, 'nik' => 'NIK-0085', 'name' => 'Endy']);
        foreach (['101' => 'Azis', '102' => 'Salma', '103' => 'Endy'] as $no => $name) {
            $this->users[] = $this->person($no, $name, 0);
        }

        // Assigned to DOOR-B in the app, not on the device.
        $this->appOnly = Employee::factory()->create(['employee_id' => '250999', 'hikvision_employee_no' => '250999', 'source_person_number' => '250999', 'nik' => 'UNVERIFIED-NIK-250999', 'name' => 'Belum Di Perangkat']);
        $this->appOnly->doors()->attach($this->doorB->id, ['sync_status' => 'pending']);
    }

    private function reconcile(): void
    {
        app(Recon::class)->run($this->admin, [$this->doorB->id]);
    }

    private function state(string $no): DevicePersonState
    {
        return DevicePersonState::where('door_id', $this->doorB->id)->where('device_employee_no', $no)->firstOrFail();
    }

    private function statuses(DevicePersonState $state): array
    {
        return [$state->device_link_status, $state->identity_status, $state->status];
    }

    // 1
    public function test_01_exact_person_number_with_placeholder_nik_is_matched_unverified_partial(): void
    {
        $this->reconcile();

        $ami = $this->state('250611');
        $this->assertSame(['MATCHED', 'UNVERIFIED', 'PARTIAL'], $this->statuses($ami));
        $this->assertSame([$this->ami->id, 'person_number', 'high'], [$ami->employee_id, $ami->match_basis, $ami->confidence]);
        $this->assertContains('nik_unverified', $ami->reasons);

        $verification = app(Recon::class)->employeeVerification($this->ami->fresh());
        $this->assertSame(['MATCHED', 'UNVERIFIED', 'PARTIAL'], [$verification['device_link_status'], $verification['identity_status'], $verification['sync_status']]);
    }

    // 2
    public function test_02_exact_person_number_without_device_name_is_matched_review_partial(): void
    {
        $this->reconcile();

        $state = $this->state('250650');
        $this->assertSame(['MATCHED', 'REVIEW', 'PARTIAL'], $this->statuses($state));
        $this->assertSame($this->blankName->id, $state->employee_id);
        $this->assertContains('device_name_missing', $state->reasons);
    }

    // 3
    public function test_03_unreported_fingerprint_count_is_unknown_not_zero(): void
    {
        $this->reconcile();

        $this->assertSame(98, DevicePersonState::whereNull('fingerprint_count')->count());
        $this->assertSame(0, DevicePersonState::where('fingerprint_status', 'NOT_ENROLLED')->count());
        $this->assertSame('UNKNOWN', $this->state('250611')->fingerprint_status);

        $summary = collect(app(Recon::class)->deviceSummaries())->firstWhere('door_code', 'DOOR-B');
        $this->assertNull($summary['fingerprints_on_device'], 'Not reported must not be shown as 0');
        $this->assertSame(98, $summary['fingerprint_unknown']);
        $this->assertSame('UNKNOWN', app(Recon::class)->employeeVerification($this->ami->fresh())['fingerprint_status']);

        $detail = $this->getJson("/api/v1/access/reconciliation/employees/{$this->ami->id}")->assertOk()->json('data');
        $this->assertNull($detail['device_states'][0]['fingerprint_count']);
        $this->assertSame('UNKNOWN', $detail['device_states'][0]['fingerprint_status']);
    }

    // 4
    public function test_04_explicit_zero_fingerprints_from_the_device_is_not_enrolled(): void
    {
        $this->users[0]['numOfFP'] = 0; // the device explicitly reports zero templates
        $this->users[1]['numOfFP'] = 2;
        $this->reconcile();

        $this->assertSame('NOT_ENROLLED', $this->state('250600')->fingerprint_status);
        $this->assertSame('ENROLLED_ON_DEVICE', $this->state('250601')->fingerprint_status);
        $this->assertSame('UNKNOWN', $this->state('250602')->fingerprint_status);
        $summary = collect(app(Recon::class)->deviceSummaries())->firstWhere('door_code', 'DOOR-B');
        $this->assertSame([2, 96], [$summary['fingerprints_on_device'], $summary['fingerprint_unknown']]);
    }

    // 5
    public function test_05_raw_identifiers_with_leading_zeroes_match_independently(): void
    {
        $this->reconcile();

        $this->assertSame([$this->vidiA->id, 'person_number', 'MATCHED'], [$this->state('00001')->employee_id, $this->state('00001')->match_basis, $this->state('00001')->device_link_status]);
        $this->assertSame([$this->vidiB->id, 'employee_id', 'MATCHED'], [$this->state('1')->employee_id, $this->state('1')->match_basis, $this->state('1')->device_link_status]);
        $this->assertSame(0, DevicePersonLink::count());
    }

    // 6
    public function test_06_name_only_candidate_is_never_linked(): void
    {
        $this->reconcile();

        $endy = $this->state('103');
        $this->assertSame(['DEVICE_ONLY', 'REVIEW', 'DEVICE_ONLY'], $this->statuses($endy));
        $this->assertSame([null, $this->endy->id, 'name_candidate', 'low'], [$endy->employee_id, $endy->candidate_employee_id, $endy->match_basis, $endy->confidence]);
        foreach (['101', '102'] as $no) {
            $this->assertSame(['DEVICE_ONLY', null], [$this->state($no)->device_link_status, $this->state($no)->candidate_employee_id]);
        }
        $this->assertSame(0, DevicePersonLink::count());
        $this->assertSame([], $this->endy->fresh()->deviceStates()->pluck('id')->all());
    }

    // 7
    public function test_07_summary_counts_device_match_and_identity_separately(): void
    {
        $this->reconcile();

        $summary = collect(app(Recon::class)->deviceSummaries())->firstWhere('door_code', 'DOOR-B');
        $this->assertSame([
            'users_on_device' => 98, 'cards_on_device' => 82, 'fingerprints_on_device' => null,
            'device_matched' => 95, 'identity_verified' => 0, 'identity_unverified' => 94, 'identity_review' => 1,
            'synced' => 0, 'partial' => 95, 'review' => 0,
            'device_only' => 3, 'app_only' => 1, 'conflicts' => 0, 'fingerprint_unknown' => 98,
        ], array_intersect_key($summary, array_flip([
            'users_on_device', 'cards_on_device', 'fingerprints_on_device', 'device_matched', 'identity_verified', 'identity_unverified',
            'identity_review', 'synced', 'partial', 'review', 'device_only', 'app_only', 'conflicts', 'fingerprint_unknown',
        ])));

        $overview = $this->getJson('/api/v1/access/reconciliation')->assertOk()->json('data');
        $this->assertSame(95, $overview['counts']['partial']);
        $this->assertSame(1, $overview['counts']['app_only']);
        $this->assertSame(0, $overview['counts']['conflict']);
        $this->assertSame(3, $overview['counts']['device_only']);
        $this->assertSame(0, $overview['counts']['review'], 'Exact matches are no longer reported as failed matches');
        $ami = collect($this->getJson('/api/v1/access/reconciliation?search=250611')->json('data.rows'))->firstWhere('kind', 'employee');
        $this->assertSame(['MATCHED', 'UNVERIFIED', 'PARTIAL'], [$ami['device_link_status'], $ami['identity_status'], $ami['sync_status']]);

        $this->withoutMockingConsoleOutput();
        $exit = Artisan::call('device:reconcile', ['--door' => [$this->doorB->id]]);
        $output = Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Device matched', $output);
        $this->assertMatchesRegularExpression('/\| DOOR-B \| ONLINE\s+\| 98\s+\| 82\s+\| -\s+\| 95\s+\| 0\s+\| 94\s+\| 1\s+\| 0\s+\| 95\s+\| 0\s+\| 3\s+\| 1\s+\| 0\s+\| 98\s+\|/', $output);
    }

    // 8 + 9
    public function test_08_09_no_employee_mutation_and_only_read_requests(): void
    {
        $before = Employee::withTrashed()->orderBy('id')->get()->map->getRawOriginal()->all();

        $this->reconcile();
        $this->postJson('/api/v1/access/reconciliation/run', ['door_id' => $this->doorB->id])->assertOk();

        $this->assertSame($before, Employee::withTrashed()->orderBy('id')->get()->map->getRawOriginal()->all(), 'No NIK, link or merge is written to employees');
        $this->assertSame(0, DevicePersonLink::count());
        $this->assertNotEmpty($this->sent);
        foreach ($this->sent as [$method, $url]) {
            $this->assertSame('POST', $method);
            $this->assertMatchesRegularExpression('#/ISAPI/AccessControl/(UserInfo|CardInfo)/Search#', $url);
        }
        $this->assertSame(2, ActivityLog::where('action', 'device_reconciliation_run')->count());
    }

    // 10
    public function test_10_rerun_is_idempotent(): void
    {
        $columns = ['door_id', 'device_employee_no', 'employee_id', 'candidate_employee_id', 'status', 'device_link_status', 'identity_status', 'card_status', 'fingerprint_status', 'match_basis', 'confidence'];
        $this->reconcile();
        $first = DevicePersonState::orderBy('id')->get($columns)->toArray();
        $firstSummary = collect(app(Recon::class)->deviceSummaries())->firstWhere('door_code', 'DOOR-B');
        $this->reconcile();

        $this->assertSame($first, DevicePersonState::orderBy('id')->get($columns)->toArray());
        $this->assertSame(98, DevicePersonState::count());
        $second = collect(app(Recon::class)->deviceSummaries())->firstWhere('door_code', 'DOOR-B');
        unset($firstSummary['last_verified_at'], $second['last_verified_at']);
        $this->assertSame($firstSummary, $second);
    }

    public function test_rows_from_before_the_split_are_mapped_conservatively(): void
    {
        $legacy = DevicePersonState::create([
            'door_id' => $this->doorB->id, 'device_employee_no' => '250611', 'device_name' => 'Ami', 'employee_id' => $this->ami->id,
            'status' => 'REVIEW', 'confidence' => 'high', 'match_basis' => 'person_number', 'present_on_device' => true,
        ]);
        $this->assertSame(['status' => 'PARTIAL', 'device_link_status' => 'MATCHED', 'identity_status' => null], Recon::stateStatuses($legacy));

        $legacyConflict = DevicePersonState::create(['door_id' => $this->doorB->id, 'device_employee_no' => '1', 'status' => 'IDENTITY_CONFLICT', 'present_on_device' => true]);
        $this->assertSame(['status' => 'CONFLICT', 'device_link_status' => 'CONFLICT', 'identity_status' => null], Recon::stateStatuses($legacyConflict));
    }

    public function test_ui_separates_link_identity_and_unknown_biometrics(): void
    {
        $js = file_get_contents(public_path('js/dashboard.js'));
        $blade = file_get_contents(resource_path('views/dashboard.blade.php'));

        foreach (['Device matched', 'Identity verified', 'Identity unverified', 'Tersinkron / Partial', 'Hanya perangkat', 'Hanya aplikasi', 'Konflik', 'Biometrik tidak diketahui'] as $label) {
            $this->assertStringContainsString("<span>{$label}</span>", $js);
        }
        $this->assertStringContainsString("UNVERIFIED: ['badge-dim', 'NIK belum terverifikasi'],", $js);
        $this->assertStringContainsString("MATCHED: ['badge-success', 'Terhubung'],", $js);
        $this->assertStringContainsString("UNKNOWN: ['badge-dim', 'FP tidak diketahui',", $js);
        $this->assertStringContainsString('<th>Identity Status</th><th>Device Link</th>', $blade);
        // A name or conflict candidate is never preselected for linking.
        $this->assertStringContainsString("if (select) select.value = String(employee?.id || '');", $js);
        $this->assertStringNotContainsString('employee?.id || candidate?.id', $js);
    }
}
