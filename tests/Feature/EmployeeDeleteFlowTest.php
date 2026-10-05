<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Building;
use App\Models\Door;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Delete Pengguna in Manajemen Pengguna. DELETE /user-management/employees/{id} deactivates
 * the employee (employment_status INACTIVE; active access requests, credentials and e-money
 * cards revoked; audit entry); nothing is
 * hard-deleted. Before this change the trash button used window.confirm(), the list also
 * showed inactive employees, so the "deleted" row stayed (as Non-Aktif) and Total Pengguna
 * did not change. Browser behaviour was verified in Chromium; these pin backend contract and
 * frontend code.
 */
class EmployeeDeleteFlowTest extends TestCase
{
    use RefreshDatabase;

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

    // ---- backend contract the UI relies on --------------------------------------------

    public function test_delete_deactivates_and_the_active_list_total_decreases(): void
    {
        Sanctum::actingAs(Admin::factory()->create(['role' => 'super_admin']));
        $building = Building::create(['code' => 'BLD-B', 'name' => 'Gedung B', 'is_active' => true]);
        $employees = Employee::factory()->count(3)->create(['employment_status' => 'ACTIVE', 'building_id' => $building->id]);
        $door = Door::create(['door_id' => 'DOOR-B', 'door_name' => 'Door B', 'location' => 'Gedung B', 'device_ip' => '10.0.0.2']);
        $employees[0]->doors()->attach($door->id, ['sync_status' => 'synced']);

        $query = '/api/v1/user-management/employees?employment_status=ACTIVE&building_id=' . $building->id;
        $this->assertSame(3, $this->getJson($query)->assertOk()->json('pagination.total_records'));

        $this->deleteJson("/api/v1/user-management/employees/{$employees[0]->id}")->assertOk()->assertJsonPath('status', 'success');

        $this->assertSame(2, $this->getJson($query)->json('pagination.total_records'), 'total_records comes back one lower');
        $this->assertNotContains($employees[0]->id, array_column($this->getJson($query)->json('data'), 'id'));
        // Deactivated, not deleted: still listed under "Semua Status", history kept.
        $this->assertSame(3, $this->getJson('/api/v1/user-management/employees?building_id=' . $building->id)->json('pagination.total_records'));
        $this->assertNull($employees[0]->fresh()->deleted_at);
        $this->assertSame('INACTIVE', $employees[0]->fresh()->employment_status);
        $this->assertSame(1, $employees[0]->fresh()->doors()->count(), 'Door assignments are kept; credentials/requests are revoked');
        $this->assertSame(1, ActivityLog::where('action', 'deactivate_employee')->count());
    }

    public function test_only_super_admin_can_delete(): void
    {
        $employee = Employee::factory()->create(['employment_status' => 'ACTIVE']);
        foreach (['building_admin', 'hrd', 'infra_admin'] as $role) {
            Sanctum::actingAs(Admin::factory()->create(['role' => $role]));
            $this->deleteJson("/api/v1/user-management/employees/{$employee->id}")->assertForbidden();
        }
        $this->assertSame('ACTIVE', $employee->fresh()->employment_status);
    }

    public function test_page_past_the_end_returns_empty_data_with_total_pages(): void
    {
        Sanctum::actingAs(Admin::factory()->create(['role' => 'super_admin']));
        Employee::factory()->count(21)->create(['employment_status' => 'ACTIVE']);
        $last = Employee::query()->orderByDesc('id')->first();
        $this->deleteJson("/api/v1/user-management/employees/{$last->id}")->assertOk();

        $res = $this->getJson('/api/v1/user-management/employees?employment_status=ACTIVE&per_page=20&page=2')->assertOk();
        $this->assertSame([], $res->json('data'));
        $this->assertSame(1, $res->json('pagination.total_pages'), 'The UI falls back to this page');
    }

    // ---- confirmation modal ------------------------------------------------------------

    public function test_trash_opens_a_confirmation_modal_with_name_and_ids(): void
    {
        $this->assertStringNotContainsString('confirm(`Apakah Anda yakin ingin menghapus data karyawan', $this->script());
        $this->assertStringContainsString('openDeleteEmployeeModal(id);', $this->functionBody('handleDeleteEmployeeBtn'));

        $open = $this->functionBody('openDeleteEmployeeModal');
        $this->assertStringContainsString("document.getElementById('employeeDeleteName').textContent = emp.name || '-';", $open);
        $this->assertStringContainsString('`User ID: ${emp.user_id || emp.employee_id || \'-\'} · NIK: ${emp.nik || \'-\'}`', $open);
        $this->assertStringContainsString("openModal('employeeDeleteModal');", $open);

        $blade = $this->bladeSource();
        $this->assertStringContainsString('<div class="modal-overlay" id="employeeDeleteModal">', $blade);
        $this->assertStringContainsString('id="employeeDeleteConfirm" onclick="confirmDeleteEmployee(this)"', $blade);
        $this->assertStringContainsString('>Hapus Pengguna</button>', $blade);
        $this->assertStringContainsString('tidak dihapus permanen', $blade);
    }

    public function test_button_is_disabled_with_loading_while_the_request_runs(): void
    {
        $busy = $this->functionBody('setDeleteEmployeeBusy');
        $this->assertStringContainsString('confirmBtn.disabled = busy;', $busy);
        $this->assertStringContainsString("'<div class=\"spinner-sm\"></div> Menghapus...'", $busy);
        $this->assertStringContainsString('cancelBtn.disabled = busy;', $busy);
        $this->assertStringContainsString('if (employeeDelete.inflight) return;', $this->functionBody('closeDeleteEmployeeModal'));
    }

    // ---- double click ------------------------------------------------------------------

    public function test_double_click_sends_one_delete(): void
    {
        $confirm = $this->functionBody('confirmDeleteEmployee');
        $this->assertStringContainsString('if (employeeDelete.inflight || !employeeDelete.id) return;', $confirm);
        $this->assertLessThan(strpos($confirm, 'apiFetch('), strpos($confirm, 'employeeDelete.inflight = true;'), 'The guard is set before the request is sent');
        $this->assertSame(1, substr_count($confirm, 'apiFetch('));
        $this->assertStringContainsString("apiFetch(`/user-management/employees/\${Number(id)}`, { method: 'DELETE', isBackground: false })", $confirm);
        $this->assertStringContainsString('employeeDelete.inflight = false;', $this->functionBody('confirmDeleteEmployee'));
        $this->assertStringContainsString('if (!canDeleteEmployees() || employeeDelete.inflight) return;', $this->functionBody('openDeleteEmployeeModal'));
    }

    // ---- success / failure -------------------------------------------------------------

    public function test_success_removes_the_row_only_after_the_backend_confirms_and_refreshes_once(): void
    {
        $confirm = $this->functionBody('confirmDeleteEmployee');
        $request = strpos($confirm, 'apiFetch(');
        $remove = strpos($confirm, 'state.employees = state.employees.filter(e => Number(e.id) !== Number(id));');
        $this->assertNotFalse($remove);
        $this->assertGreaterThan($request, $remove, 'No optimistic removal before the backend succeeds');
        $this->assertStringContainsString("if (res?.status !== 'success') throw new Error", $confirm);
        $this->assertStringContainsString("closeModal('employeeDeleteModal');", $confirm);
        $this->assertStringContainsString('renderEmployeesTable(state.employees);', $confirm);
        // One reload of the list (pagination + total_records from the backend), forced KPIs.
        $this->assertSame(1, substr_count($confirm, 'await loadEmployees(page);'));
        $this->assertStringContainsString('scheduleMetricCardsUpdate(true);', $confirm);
        $this->assertStringContainsString('markAccessViewsStale();', $confirm);
        $this->assertStringContainsString("scheduleBackgroundRefresh('doors', loadDoors, 0);", $confirm);
    }

    public function test_failure_keeps_the_row_and_shows_the_backend_message(): void
    {
        $confirm = $this->functionBody('confirmDeleteEmployee');
        $catch = substr($confirm, strpos($confirm, '} catch (err) {'));
        $this->assertStringContainsString('showDeleteEmployeeError(deleteEmployeeErrorMessage(err));', $catch);
        $this->assertStringNotContainsString('state.employees =', $catch);
        $this->assertStringNotContainsString('closeModal(', $catch);

        $message = $this->functionBody('deleteEmployeeErrorMessage');
        $this->assertStringContainsString("return err?.message || 'Gagal menghapus pengguna.';", $message, 'Backend message (apiFetch uses data.message) is shown');
        $this->assertStringContainsString('if (err?.status === 403)', $message);
    }

    // ---- pagination, filters, scroll ----------------------------------------------------

    public function test_last_row_of_last_page_falls_back_to_the_previous_page(): void
    {
        $this->assertStringContainsString('const page = state.employees.length === 0 && state.employeePage > 1 ? state.employeePage - 1 : state.employeePage;', $this->functionBody('confirmDeleteEmployee'));

        $load = $this->functionBody('loadEmployees');
        $this->assertStringContainsString('if (res.data.length === 0 && state.employeePage > lastPage) {', $load);
        $this->assertStringContainsString('return loadEmployees(lastPage);', $load);
    }

    public function test_filters_page_and_scroll_are_kept(): void
    {
        $load = $this->functionBody('loadEmployees');
        // Building, door, search and status filters are read from their controls on every load.
        $this->assertStringContainsString("params.set('building_id', state.buildingFilter);", $load);
        $this->assertStringContainsString("params.set('door_id', doorFilter);", $load);
        $this->assertStringContainsString("params.set('employment_status', statusFilter);", $load);
        $this->assertStringContainsString("const statusFilter = document.getElementById('employeeStatusFilter')?.value ?? 'ACTIVE';", $load);
        $this->assertStringContainsString('window.scrollTo(0, previousScrollY);', $load);
        $this->assertStringContainsString('employeeRequestController.abort();', $load);
        $this->assertStringNotContainsString('location.reload', $this->functionBody('confirmDeleteEmployee'));

        $this->assertStringContainsString('<select id="employeeStatusFilter" onchange="loadEmployees(1)"', $this->bladeSource());
        $this->assertStringContainsString('<option value="ACTIVE" selected>Status: Aktif</option>', $this->bladeSource());
    }

    public function test_delete_button_only_for_super_admin_and_active_rows(): void
    {
        $this->assertStringContainsString("return window.APP_CONFIG?.admin?.role === 'super_admin';", $this->functionBody('canDeleteEmployees'));
        $this->assertStringContainsString("\${canDeleteEmployees() && emp.employment_status !== 'INACTIVE' ? `<button class=\"btn-sm btn-delete\"", $this->functionBody('renderEmployeesTable'));
    }

    public function test_async_handler_is_exposed_for_the_inline_onclick(): void
    {
        $exports = substr($this->script(), strrpos($this->script(), 'Object.assign(window, {'));
        $this->assertStringContainsString('    confirmDeleteEmployee,', $exports);
    }
}
