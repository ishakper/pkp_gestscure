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

/**
 * Production 9f62e6e: the Edit Pengguna modal showed the building but "Belum ada data divisi"
 * and "Pilih divisi terlebih dahulu" although divisions existed. The organization lookup is
 * cached at dashboard boot and the form never re-read it, so masters created after the page
 * loaded (another tab or admin) never appeared; the table row the modal opened from could be
 * older than the record, and Simpan then posted division_id/position_id = null. Async renders
 * had no sequence guard, and a stored entry missing from the lookup silently became empty.
 *
 * Runtime behaviour was verified in Chromium (see the follow-up report); these pin the code.
 */
class EmployeeEditOrganizationReactivityTest extends TestCase
{
    use RefreshDatabase;

    private function script(): string
    {
        return file_get_contents(public_path('js/dashboard.js'));
    }

    private function functionBody(string $name): string
    {
        $script = $this->script();
        $this->assertSame(1, preg_match('/\n(?:async\s+)?function\s+' . preg_quote($name, '/') . '\s*\([^)]*\)\s*\{/', $script, $m, PREG_OFFSET_CAPTURE), "function {$name}() is not defined");
        $offset = $m[0][1];
        $next = preg_match('/\n(?:async\s+)?function\s+\w+\s*\(/', $script, $n, PREG_OFFSET_CAPTURE, $offset + 1) ? $n[0][1] : strlen($script);

        return substr($script, $offset, $next - $offset);
    }

    // 1: opening before the lookup is ready shows a loading state, not "Belum ada data"
    public function test_01_form_shows_loading_until_a_fresh_lookup_is_available(): void
    {
        $load = $this->functionBody('loadEmployeeOrganizationOptions');
        $this->assertStringContainsString('if (organizationLookupLoadedAt && Date.now() - organizationLookupLoadedAt <= ORGANIZATION_LOOKUP_FRESH_MS) {', $load);
        $this->assertStringContainsString('renderEmployeeOrganizationLoading();', $load);
        $this->assertStringContainsString("setEmployeeOrganizationState('loading');", $load);
        $this->assertStringContainsString("'Memuat data organisasi...'", $this->script());
    }

    // 2: the form re-reads a lookup older than the freshness window; in-flight requests are shared
    public function test_02_lookup_is_refreshed_for_the_form_and_shared_while_in_flight(): void
    {
        $ensure = $this->functionBody('ensureOrganizationLookup');
        $this->assertStringContainsString('if (organizationLookupPromise && organizationLookupLoadedAt && Date.now() - organizationLookupLoadedAt > maxAgeMs) {', $ensure);
        $this->assertStringContainsString('organizationLookupLoadedAt = 0;', $ensure);
        $this->assertStringContainsString('organizationLookupLoadedAt = Date.now();', $ensure);
        $this->assertStringContainsString('ensureOrganizationLookup({ maxAgeMs: ORGANIZATION_LOOKUP_FRESH_MS })', $this->functionBody('loadEmployeeOrganizationOptions'));
        $this->assertSame(1, preg_match('/const ORGANIZATION_LOOKUP_FRESH_MS = (\d+);/', $this->script(), $m));
        $this->assertGreaterThanOrEqual(15000, (int) $m[1], 'Keep the window long enough that opening the form repeatedly sends no burst');
        $this->assertSame(1, substr_count($this->script(), "apiFetch('/user-management/organization/lookup')"));
    }

    // 3-5: stored building/division/position restored from a fresh record
    public function test_03_05_edit_restores_selection_from_a_fresh_record(): void
    {
        $open = $this->functionBody('openEditEmployeeModal');
        $this->assertStringContainsString('organizationLegacyFor(emp)', $open);
        $this->assertStringContainsString('refreshEditedEmployee(emp.id);', $open);

        $refresh = $this->functionBody('refreshEditedEmployee');
        $this->assertStringContainsString('apiFetch(`/user-management/employees/${Number(id)}`)', $refresh);
        $this->assertStringContainsString('if (seq !== employeeEditSeq || !modalOpen || String(document.getElementById(\'empDbId\').value) !== String(id) || !res?.data) return;', $refresh);
        $this->assertStringContainsString('patchEmployeeRow(res.data);', $refresh);
        $this->assertStringContainsString('if (!employeeOrganizationForm.touched) {', $refresh);

        // The single-employee endpoint returns what the form restores.
        Sanctum::actingAs(Admin::factory()->create(['role' => 'super_admin']));
        $building = Building::create(['code' => 'BLD-A', 'name' => 'Gedung A', 'is_active' => true]);
        $division = Division::create(['code' => 'DIV-IT', 'name' => 'IT', 'building_id' => $building->id, 'is_active' => true]);
        $position = Position::create(['code' => 'POS-HD', 'name' => 'Helpdesk', 'division_id' => $division->id, 'is_active' => true]);
        $emp = Employee::factory()->create(['building_id' => $building->id, 'division_id' => $division->id, 'position_id' => $position->id]);

        $data = $this->getJson("/api/v1/user-management/employees/{$emp->id}")->assertOk()->json('data');
        $this->assertSame([$building->id, $division->id, $position->id], [$data['building']['id'], $data['division']['id'], $data['position']['id']]);
        $this->assertArrayHasKey('device_verification', $data);
    }

    // 6-7: parent change resets children that do not belong to it, including a legacy entry
    public function test_06_07_parent_changes_reset_invalid_children(): void
    {
        $change = $this->functionBody('onEmployeeOrganizationChange');
        $this->assertStringContainsString("if ((document.getElementById('empBuilding')?.value || '') !== String(previous.building || '')) {", $change);
        $this->assertStringContainsString('legacy.division = null;', $change);
        $this->assertStringContainsString('legacy.position = null;', $change);
        $this->assertStringContainsString('employeeOrganizationForm.selection = readEmployeeOrganizationSelection();', $change);

        $render = $this->functionBody('renderEmployeeOrganizationOptions');
        $this->assertStringContainsString('.filter(d => String(d.building_id) === buildingId)', $render);
        $this->assertStringContainsString('.filter(p => String(p.division_id) === divisionId)', $render);
    }

    // 8: empty master vs nothing for this parent
    public function test_08_empty_master_messages(): void
    {
        $render = $this->functionBody('renderEmployeeOrganizationOptions');
        $this->assertStringContainsString("allDivisions.length ? 'Belum ada divisi untuk gedung ini' : 'Belum ada data divisi'", $render);
        $this->assertStringContainsString("allPositions.length ? 'Belum ada posisi untuk divisi ini' : 'Belum ada data posisi'", $render);
    }

    // 9: stale async renders are dropped and never reset a user's choice
    public function test_09_sequence_guard_and_user_selection_survive_async_refresh(): void
    {
        $load = $this->functionBody('loadEmployeeOrganizationOptions');
        $this->assertStringContainsString('const seq = ++employeeOrganizationForm.seq;', $load);
        $this->assertStringContainsString('if (seq !== employeeOrganizationForm.seq) return; // a newer open/refresh owns the form', $load);
        $this->assertStringContainsString('renderEmployeeOrganizationOptions(employeeOrganizationForm.selection, employeeOrganizationForm.legacy);', $load);
        $this->assertStringContainsString('{ keepTouched: true }', $this->functionBody('invalidateOrganizationLookup'));
        $this->assertStringContainsString('employeeEditSeq++;', $this->functionBody('openAddEmployeeModal'));
    }

    // legacy entry visible and left untouched on save
    public function test_legacy_entries_are_visible_and_never_cleared_silently(): void
    {
        $this->assertStringContainsString("division: 'Data divisi lama tidak ditemukan',", $this->script());
        $fill = $this->functionBody('fillOrganizationSelect');
        $this->assertStringContainsString('data-legacy="1"', $fill);

        $payload = $this->functionBody('employeeOrganizationPayload');
        $this->assertStringContainsString("if (orgState === 'loading' || orgState === 'error') return {};", $payload);
        $this->assertStringContainsString('if (employeeOrganizationForm.recordPending && !employeeOrganizationForm.touched) return {};', $payload);
        $this->assertStringContainsString('if (!select || select.selectedOptions[0]?.dataset.legacy) return;', $payload);
        $this->assertStringContainsString('...employeeOrganizationPayload(),', $this->functionBody('saveEmployee'));
        $this->assertStringNotContainsString("division_id: document.getElementById('empDivision').value", $this->functionBody('saveEmployee'));

        // Backend: omitted organization fields keep the stored values.
        Sanctum::actingAs(Admin::factory()->create(['role' => 'super_admin']));
        $building = Building::create(['code' => 'BLD-A', 'name' => 'Gedung A', 'is_active' => true]);
        $division = Division::create(['code' => 'DIV-OLD', 'name' => 'Lama', 'building_id' => $building->id, 'is_active' => false]);
        $emp = Employee::factory()->create(['building_id' => $building->id, 'division_id' => $division->id]);
        $this->putJson("/api/v1/user-management/employees/{$emp->id}", ['name' => 'Tetap Divisi Lama', 'building_id' => $building->id])->assertOk();
        $this->assertSame($division->id, $emp->fresh()->division_id);
    }

    // 10, 13, 14: row reactive, filters kept, no duplicate fetch
    public function test_10_13_14_save_patches_row_and_reloads_once(): void
    {
        $save = $this->functionBody('saveEmployee');
        $this->assertStringContainsString('if (id) patchEmployeeRow(res.data);', $save);
        $this->assertSame(1, substr_count($save, 'loadEmployees('));
        $this->assertStringNotContainsString('location.reload', $save);
        $load = $this->functionBody('loadEmployees');
        $this->assertStringContainsString('requestAnimationFrame', $load);
        $this->assertStringContainsString('employeeRequestController.abort();', $load);
    }

    // 11: device truth only from reconciliation evidence
    public function test_11_unverified_device_state_stays_unknown(): void
    {
        $badges = $this->functionBody('employeeCredentialBadges');
        $this->assertStringContainsString('const verified = Boolean(verification?.last_verified_at);', $badges);
        $this->assertStringContainsString("verified && DEVICE_FP_BADGES[verification.fingerprint_status] ? verification.fingerprint_status : 'UNKNOWN'", $badges);
        $this->assertStringNotContainsString('biometric_status?.fingerprint_enrolled ?', $badges);

        Sanctum::actingAs(Admin::factory()->create(['role' => 'super_admin']));
        $emp = Employee::factory()->create(['card_registered' => true]);
        $data = $this->putJson("/api/v1/user-management/employees/{$emp->id}", ['fingerprint_enrolled' => true, 'card_enrolled' => true])->assertOk()->json('data.device_verification');
        $this->assertSame(['UNKNOWN', 'UNKNOWN', null], [$data['card_status'], $data['fingerprint_status'], $data['last_verified_at']]);
    }

    // 12: access views refresh after employee / door access changes
    public function test_12_access_views_refresh_after_changes(): void
    {
        $this->assertStringContainsString('reconState.loadedAt = 0;', $this->functionBody('markAccessViewsStale'));
        $save = $this->functionBody('saveEmployee');
        $this->assertStringContainsString('markAccessViewsStale();', $save);
        $this->assertStringContainsString("if (payload.employment_status === 'INACTIVE') scheduleBackgroundRefresh('doors', loadDoors, 0);", $save);
        foreach (['submitDoorAssignment', 'revokeAllEmployeeDoors', 'revokeSingleDoor'] as $fn) {
            $body = $this->functionBody($fn);
            $this->assertStringContainsString('markAccessViewsStale();', $body);
            $this->assertStringContainsString('await loadEmployees();', $body);
        }
        // Organization membership is never derived from door assignments.
        $this->assertStringNotContainsString('door_assign', $this->functionBody('renderEmployeeOrganizationOptions'));
        $this->assertStringNotContainsString('door_assign', $this->functionBody('employeeOrganizationPayload'));
    }

    // d295ef8: choosing a Gedung rendered Divisi from the cached lookup only. A division
    // created after the modal opened (another tab/admin, within the 30 s window) kept the
    // child on a disabled "Belum ada data divisi". Reproduced in Chromium; now the change
    // re-reads the masters with a loading state on the dependent level.
    public function test_changing_building_or_division_refreshes_stale_masters_with_loading_state(): void
    {
        $change = $this->functionBody('onEmployeeOrganizationChange');
        $this->assertStringContainsString('refreshEmployeeOrganizationAfterChange();', $change);
        $this->assertLessThan(strpos($change, 'refreshEmployeeOrganizationAfterChange();'), strpos($change, 'employeeOrganizationForm.selection = readEmployeeOrganizationSelection();'), 'The refresh uses the selection after the cascade reset');

        $refresh = $this->functionBody('refreshEmployeeOrganizationAfterChange');
        $this->assertStringContainsString("if (!building) return; // nothing below Gedung can be chosen yet", $refresh);
        $this->assertStringContainsString('if (organizationLookupLoadedAt && Date.now() - organizationLookupLoadedAt <= ORGANIZATION_CHANGE_FRESH_MS) return;', $refresh);
        $this->assertStringContainsString("document.getElementById(division ? 'empPosition' : 'empDivision')", $refresh);
        $this->assertStringContainsString("'Memuat data posisi...' : 'Memuat data divisi...'", $refresh);
        $this->assertStringContainsString("setEmployeeOrganizationState('refreshing');", $refresh);
        $this->assertStringContainsString('ensureOrganizationLookup({ maxAgeMs: ORGANIZATION_CHANGE_FRESH_MS })', $refresh);
        $this->assertStringContainsString('if (seq !== employeeOrganizationForm.seq) return; // a newer change/open owns the form', $refresh);
        $this->assertStringContainsString('renderEmployeeOrganizationOptions(employeeOrganizationForm.selection, employeeOrganizationForm.legacy);', $refresh);
        // A failed refresh still leaves the selects usable (cached data), never stuck on loading.
        $this->assertLessThan(strpos($refresh, '.then('), strpos($refresh, '.catch('));
    }

    public function test_change_refresh_window_prevents_bursts_and_does_not_block_saving(): void
    {
        $this->assertSame(1, preg_match('/const ORGANIZATION_CHANGE_FRESH_MS = (\d+);/', $this->script(), $m));
        $this->assertGreaterThanOrEqual(3000, (int) $m[1], 'Switching buildings repeatedly sends at most one lookup per window');
        $this->assertSame(1, substr_count($this->script(), "apiFetch('/user-management/organization/lookup')"));
        // "refreshing" keeps Gedung usable and its value in the payload; only loading/error hold it back.
        $this->assertStringContainsString("if (orgState === 'loading' || orgState === 'error') return {};", $this->functionBody('employeeOrganizationPayload'));
    }

    public function test_building_with_no_divisions_shows_empty_state_not_choose_building(): void
    {
        $render = $this->functionBody('renderEmployeeOrganizationOptions');
        // The child is blocked only when no Gedung is selected; an empty master is an empty state.
        $this->assertStringContainsString("buildingId ? '' : 'Pilih gedung terlebih dahulu'", $render);
        $this->assertStringContainsString("allDivisions.length ? 'Belum ada divisi untuk gedung ini' : 'Belum ada data divisi'", $render);
        $this->assertStringContainsString("const buildingId = document.getElementById('empBuilding')?.value || '';", $render);
        $this->assertStringContainsString('<select id="empBuilding" onchange="onEmployeeOrganizationChange()">', file_get_contents(resource_path('views/dashboard.blade.php')));
        $this->assertStringContainsString('<select id="empDivision" onchange="onEmployeeOrganizationChange()">', file_get_contents(resource_path('views/dashboard.blade.php')));
    }
}
