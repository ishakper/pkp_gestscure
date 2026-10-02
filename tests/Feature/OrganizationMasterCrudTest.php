<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Building;
use App\Models\Division;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Setup Gedung -> Divisi / Posisi on top of the existing OrganizationController contract
 * (GET/POST/PUT /user-management/organization/{type}, super admin only for writes and full
 * lists; GET /user-management/organization/lookup for everyone, active + scoped).
 * Production starts with buildings = 4, divisions = 0, positions = 0.
 */
class OrganizationMasterCrudTest extends TestCase
{
    use RefreshDatabase;

    private Building $gedungA;
    private Building $gedungB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gedungA = Building::create(['code' => 'BLD-A', 'name' => 'Gedung A', 'is_active' => true]);
        $this->gedungB = Building::create(['code' => 'BLD-B', 'name' => 'Gedung B', 'is_active' => true]);
    }

    private function actingAsRole(string $role, array $extra = []): Admin
    {
        $admin = Admin::factory()->create(array_merge(['role' => $role], $extra));
        Sanctum::actingAs($admin);

        return $admin;
    }

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

    private function lookup(): array
    {
        return $this->getJson('/api/v1/user-management/organization/lookup')->assertOk()->json('data');
    }

    // 1-3: divisions
    public function test_super_admin_creates_and_edits_a_division_that_shows_up_in_lookup(): void
    {
        $this->actingAsRole('super_admin');

        $id = $this->postJson('/api/v1/user-management/organization/divisions', ['code' => 'DIV-IT', 'name' => 'IT Support', 'building_id' => $this->gedungA->id])
            ->assertCreated()->json('data.id');
        $this->assertSame(['IT Support'], array_column($this->lookup()['divisions'], 'name'));

        $this->putJson("/api/v1/user-management/organization/divisions/{$id}", ['code' => 'DIV-IT', 'name' => 'IT & Infrastruktur', 'building_id' => $this->gedungA->id])
            ->assertOk()->assertJsonPath('data.name', 'IT & Infrastruktur');
        $this->assertSame([['id' => $id, 'building_id' => $this->gedungA->id, 'code' => 'DIV-IT', 'name' => 'IT & Infrastruktur']], $this->lookup()['divisions']);
    }

    // 4-6: positions
    public function test_super_admin_creates_and_edits_a_position_that_shows_up_in_lookup(): void
    {
        $this->actingAsRole('super_admin');
        $division = Division::create(['code' => 'DIV-IT', 'name' => 'IT', 'building_id' => $this->gedungA->id, 'is_active' => true]);

        $id = $this->postJson('/api/v1/user-management/organization/positions', ['code' => 'POS-STF', 'name' => 'Staff IT', 'division_id' => $division->id])
            ->assertCreated()->json('data.id');
        $this->assertSame(['Staff IT'], array_column($this->lookup()['positions'], 'name'));

        $this->putJson("/api/v1/user-management/organization/positions/{$id}", ['code' => 'POS-STF', 'name' => 'Staff IT Support', 'division_id' => $division->id])->assertOk();
        $this->assertSame('Staff IT Support', $this->lookup()['positions'][0]['name']);
    }

    public function test_deactivated_entries_leave_the_lookup_but_stay_in_the_full_list(): void
    {
        $this->actingAsRole('super_admin');
        $division = Division::create(['code' => 'DIV-IT', 'name' => 'IT', 'building_id' => $this->gedungA->id, 'is_active' => true]);

        $this->putJson("/api/v1/user-management/organization/divisions/{$division->id}", ['code' => 'DIV-IT', 'name' => 'IT', 'building_id' => $this->gedungA->id, 'is_active' => false])->assertOk();

        $this->assertSame([], $this->lookup()['divisions']);
        $this->assertSame([false], array_column($this->getJson('/api/v1/user-management/organization/divisions')->assertOk()->json('data'), 'is_active'));
        $this->assertDatabaseHas('divisions', ['id' => $division->id]); // deactivated, not deleted
    }

    public function test_validation_rejects_missing_fields_and_duplicate_codes(): void
    {
        $this->actingAsRole('super_admin');
        Division::create(['code' => 'DIV-IT', 'name' => 'IT', 'building_id' => $this->gedungA->id, 'is_active' => true]);

        $this->postJson('/api/v1/user-management/organization/divisions', ['building_id' => $this->gedungA->id])->assertStatus(422)->assertJsonValidationErrors(['code', 'name']);
        $this->postJson('/api/v1/user-management/organization/divisions', ['code' => 'DIV-IT', 'name' => 'Dup', 'building_id' => $this->gedungB->id])->assertStatus(422)->assertJsonValidationErrors(['code']);
        $this->postJson('/api/v1/user-management/organization/positions', ['code' => 'P', 'name' => 'P', 'division_id' => 999999])->assertStatus(422)->assertJsonValidationErrors(['division_id']);
    }

    // 7-8: hierarchy as seen by the employee form
    public function test_lookup_carries_the_parent_ids_the_cascade_filters_on(): void
    {
        $this->actingAsRole('super_admin');
        $divA = Division::create(['code' => 'DIV-A', 'name' => 'Div A', 'building_id' => $this->gedungA->id, 'is_active' => true]);
        $divB = Division::create(['code' => 'DIV-B', 'name' => 'Div B', 'building_id' => $this->gedungB->id, 'is_active' => true]);
        Position::create(['code' => 'POS-A', 'name' => 'Pos A', 'division_id' => $divA->id, 'is_active' => true]);
        Position::create(['code' => 'POS-B', 'name' => 'Pos B', 'division_id' => $divB->id, 'is_active' => true]);

        $lookup = $this->lookup();
        $divisionsOfA = array_values(array_filter($lookup['divisions'], fn ($d) => $d['building_id'] === $this->gedungA->id));
        $positionsOfDivA = array_values(array_filter($lookup['positions'], fn ($p) => $p['division_id'] === $divA->id));

        $this->assertSame(['Div A'], array_column($divisionsOfA, 'name'));
        $this->assertSame(['Pos A'], array_column($positionsOfDivA, 'name'));
    }

    // 9: empty master
    public function test_empty_master_tables_return_empty_lists(): void
    {
        $this->actingAsRole('super_admin');

        $this->assertSame([], $this->getJson('/api/v1/user-management/organization/divisions')->assertOk()->json('data'));
        $this->assertSame([], $this->getJson('/api/v1/user-management/organization/positions')->assertOk()->json('data'));
        $lookup = $this->lookup();
        $this->assertCount(2, $lookup['buildings']);
        $this->assertSame([], $lookup['divisions']);
        $this->assertSame([], $lookup['positions']);
    }

    // 11: authorization unchanged
    public function test_only_super_admin_can_list_create_or_update_master_entries(): void
    {
        $division = Division::create(['code' => 'DIV-IT', 'name' => 'IT', 'building_id' => $this->gedungA->id, 'is_active' => true]);

        foreach (['infra_admin', 'hrd', 'management', 'employee'] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/v1/user-management/organization/divisions')->assertForbidden();
            $this->postJson('/api/v1/user-management/organization/divisions', ['code' => "X-{$role}", 'name' => 'X', 'building_id' => $this->gedungA->id])->assertForbidden();
            $this->putJson("/api/v1/user-management/organization/divisions/{$division->id}", ['code' => 'DIV-IT', 'name' => 'Hacked', 'building_id' => $this->gedungA->id])->assertForbidden();
            $this->postJson('/api/v1/user-management/organization/positions', ['code' => "P-{$role}", 'name' => 'P', 'division_id' => $division->id])->assertForbidden();
        }
        $this->assertSame('IT', $division->fresh()->name);
        $this->assertSame(1, Division::count());
        $this->assertSame(0, Position::count());
    }

    public function test_building_admin_cannot_write_and_its_lookup_stays_scoped_to_its_building(): void
    {
        $divA = Division::create(['code' => 'DIV-A', 'name' => 'Div A', 'building_id' => $this->gedungA->id, 'is_active' => true]);
        Division::create(['code' => 'DIV-B', 'name' => 'Div B', 'building_id' => $this->gedungB->id, 'is_active' => true]);
        Position::create(['code' => 'POS-A', 'name' => 'Pos A', 'division_id' => $divA->id, 'is_active' => true]);

        $this->actingAsRole('building_admin', ['assigned_building' => 'Gedung A']);

        $this->postJson('/api/v1/user-management/organization/divisions', ['code' => 'NEW', 'name' => 'New', 'building_id' => $this->gedungA->id])->assertForbidden();
        $lookup = $this->lookup();
        $this->assertSame(['Gedung A'], array_column($lookup['buildings'], 'name'));
        $this->assertSame(['Div A'], array_column($lookup['divisions'], 'name'));
        $this->assertSame(['Pos A'], array_column($lookup['positions'], 'name'));
    }

    // UI gating mirrors the backend
    public function test_write_controls_are_rendered_for_super_admin_only(): void
    {
        $blade = file_get_contents(resource_path('views/dashboard.blade.php'));
        $this->assertMatchesRegularExpression("/@if\\(\\\$admin\\?->isSuperAdmin\\(\\)\\)\\s*<button class=\"btn-primary\" onclick=\"openOrganizationMasterModal\\('divisions'\\)\">/", $blade);
        $this->assertMatchesRegularExpression("/@if\\(\\\$admin\\?->isSuperAdmin\\(\\)\\)\\s*<button class=\"btn-primary\" onclick=\"openOrganizationMasterModal\\('positions'\\)\">/", $blade);
        $this->assertStringContainsString("return window.APP_CONFIG?.admin?.role === 'super_admin';", $this->functionBody('canManageOrganizationMaster'));
        $this->assertStringContainsString('if (organizationMaster.readOnly) return', $this->functionBody('organizationRowActions'));
    }

    // 10: employee form hierarchy
    public function test_employee_form_cascade_is_strict_and_disables_unchosen_levels(): void
    {
        $render = $this->functionBody('renderEmployeeOrganizationOptions');

        $this->assertStringContainsString('.filter(d => String(d.building_id) === buildingId)', $render);
        $this->assertStringContainsString('.filter(p => String(p.division_id) === divisionId)', $render);
        $this->assertStringContainsString("buildingId ? '' : 'Pilih gedung terlebih dahulu'", $render);
        $this->assertStringContainsString("divisionId ? '' : 'Pilih divisi terlebih dahulu'", $render);
        $this->assertStringContainsString("'Belum ada data divisi'", $render);
        $this->assertStringContainsString("'Belum ada data posisi'", $render);
    }

    // 12: cache invalidation without reload
    public function test_master_changes_invalidate_the_lookup_and_refresh_an_open_employee_form(): void
    {
        $invalidate = $this->functionBody('invalidateOrganizationLookup');
        $this->assertStringContainsString('organizationLookupPromise = null;', $invalidate);
        $this->assertStringContainsString("getElementById('employeeModal')?.classList.contains('active')", $invalidate);
        $this->assertStringContainsString('loadEmployeeOrganizationOptions({', $invalidate);

        foreach (['submitOrganizationMaster', 'toggleOrganizationMasterActive'] as $fn) {
            $body = $this->functionBody($fn);
            $this->assertStringContainsString('invalidateOrganizationLookup();', $body);
            $this->assertStringContainsString('await refreshOrganizationMasterList(type);', $body);
        }
    }

    // 13: no duplicate requests
    public function test_master_data_is_loaded_once_and_mutations_refetch_only_the_changed_list(): void
    {
        $load = $this->functionBody('loadOrganizationMaster');
        $this->assertStringContainsString('if (organizationMaster.loading) return organizationMaster.loading;', $load);
        $this->assertStringContainsString('if (organizationMaster.loaded && !force) {', $load);
        $this->assertStringContainsString('ensureOrganizationLookup()', $load, 'Buildings come from the shared lookup cache');

        // Dashboard filter, attendance filter, employee form and Setup Gedung share one lookup request.
        $this->assertSame(1, substr_count($this->script(), "apiFetch('/user-management/organization/lookup')"));
        $this->assertStringContainsString('await ensureOrganizationLookup()', $this->functionBody('loadDashboardBuildings'));
        $this->assertStringContainsString('await ensureOrganizationLookup()', $this->functionBody('ensureAttendanceReportBuildings'));

        $refresh = $this->functionBody('refreshOrganizationMasterList');
        $this->assertSame(1, substr_count($refresh, 'apiFetch('));

        $submit = $this->functionBody('submitOrganizationMaster');
        $this->assertStringContainsString('submit.disabled = true; // one request per click', $submit);
        $this->assertStringContainsString("const missing = [", $submit, 'Client-side required check before any request');
    }
}
