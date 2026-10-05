<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Door;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Production: editing an employee and ticking "Fingerprint tercatat di aplikasi" succeeded,
 * the row showed "Tercatat di aplikasi: FP", the device badge stayed "FP tidak diketahui" and
 * DOOR-B stayed pending. Correct data, but the row mixed app records, device state and door
 * access in one cell, door pills showed raw "pending", and the modal/toast suggested the save
 * synchronised hardware. Verified in Chromium; these pin the separation and the reactivity.
 */
class EmployeeAppVsDeviceUxTest extends TestCase
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

    // ---- reactivity after PUT employee -------------------------------------------------

    public function test_put_response_carries_app_record_without_changing_device_truth_or_sync(): void
    {
        Sanctum::actingAs(Admin::factory()->create(['role' => 'super_admin']));
        $door = Door::create(['door_id' => 'DOOR-B', 'door_name' => 'Door B', 'location' => 'Gedung B', 'device_ip' => '10.0.0.2']);
        $employee = Employee::factory()->create();
        $employee->doors()->attach($door->id, ['sync_status' => 'pending']);

        $data = $this->putJson("/api/v1/user-management/employees/{$employee->id}", ['fingerprint_enrolled' => true])->assertOk()->json('data');

        $this->assertTrue($data['device_verification']['app_recorded']['fingerprint'], 'Row can show "Tercatat di aplikasi: FP" straight from the response');
        $this->assertSame('UNKNOWN', $data['device_verification']['fingerprint_status'], 'Device truth is not upgraded by the checkbox');
        $this->assertSame([['id' => $door->id, 'door_id' => 'DOOR-B', 'sync_status' => 'pending', 'last_synced_at' => null]], array_map(
            fn ($d) => array_intersect_key($d, array_flip(['id', 'door_id', 'sync_status', 'last_synced_at'])),
            $data['door_assign']
        ), 'Saving the employee does not touch the door sync state');
        $this->assertSame('pending', $employee->doors()->first()->pivot->sync_status);
    }

    public function test_door_assign_exposes_last_synced_at_and_door_key(): void
    {
        Sanctum::actingAs(Admin::factory()->create(['role' => 'super_admin']));
        $door = Door::create(['door_id' => 'DOOR-A', 'door_name' => 'Door A', 'location' => 'Gedung A', 'device_ip' => '10.0.0.1']);
        $employee = Employee::factory()->create();
        $employee->doors()->attach($door->id, ['sync_status' => 'synced', 'last_synced_at' => '2026-10-05 08:00:00']);

        $row = collect($this->getJson('/api/v1/user-management/employees?per_page=20')->assertOk()->json('data'))->firstWhere('id', $employee->id);
        $this->assertSame($door->id, $row['door_assign'][0]['id']);
        $this->assertStringStartsWith('2026-10-05T08:00:00', $row['door_assign'][0]['last_synced_at']);
    }

    public function test_save_patches_the_row_from_the_response_and_never_writes_to_devices(): void
    {
        $save = $this->functionBody('saveEmployee');
        $this->assertStringContainsString('if (id) patchEmployeeRow(res.data);', $save);
        $this->assertSame(1, substr_count($save, 'apiFetch('), 'Only the employee PUT/POST');
        foreach (['assign-doors', 'sync-hardware', '/ISAPI', 'retry', 'reconciliation/run'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $save);
        }
        $this->assertStringContainsString("'Data karyawan disimpan (data aplikasi). Status perangkat dan akses pintu tidak berubah; kelola lewat Kelola Akses.'", $save);
        $this->assertStringNotContainsString("sync_status: 'synced'", $this->script(), 'Nothing marks an assignment synced on the client');
    }

    // ---- three separate groups ---------------------------------------------------------

    public function test_row_separates_device_state_app_record_and_door_access(): void
    {
        $render = $this->functionBody('renderEmployeesTable');
        $this->assertStringContainsString('const { fpBadge, cardBadge, syncBadge, appBadge } = employeeCredentialBadges(emp);', $render);
        $this->assertStringContainsString('<div class="cred-group" data-group="device">', $render);
        $this->assertStringContainsString('>Status Perangkat</small>', $render);
        $this->assertStringContainsString('<div class="bio-pill-group">${fpBadge}${cardBadge}${syncBadge}</div>', $render);
        $this->assertStringContainsString('<div class="cred-group" data-group="app">', $render);
        $this->assertStringContainsString('>Catatan Aplikasi</small>', $render);
        $this->assertStringContainsString("\${appBadge || '<span class=\"badge badge-dim\">—</span>'}", $render);

        $badges = $this->functionBody('employeeCredentialBadges');
        $this->assertStringContainsString("verified && DEVICE_FP_BADGES[verification.fingerprint_status] ? verification.fingerprint_status : 'UNKNOWN'", $badges);
        $this->assertStringContainsString('syncBadge,', $badges);
        $this->assertStringContainsString('appBadge,', $badges);
        $this->assertStringNotContainsString('syncBadge + appBadge', $badges, 'App record is not mixed into the device group');

        $this->assertSame(2, substr_count($this->bladeSource(), '<th>Status Perangkat &amp; Catatan Aplikasi</th>'));
        $this->assertSame(2, substr_count($this->bladeSource(), '<th>Hak Akses Pintu</th>'));
    }

    public function test_door_access_labels(): void
    {
        $this->assertStringContainsString("pending: ['badge-pending', '⏳', 'Menunggu sinkronisasi'],", $this->script());
        $this->assertStringContainsString("synced: ['badge-synced', '✓', 'Aktif di perangkat'],", $this->script());
        $this->assertStringContainsString("failed: ['badge-failed', '✕', 'Sinkronisasi gagal'],", $this->script());

        $render = $this->functionBody('renderEmployeesTable');
        $this->assertStringContainsString('const [badgeCls, icon, label] = doorAccessSyncLabel(d.sync_status);', $render);
        $this->assertStringContainsString('${icon} ${escapeHtml(d.door_id)}: ${label}', $render);
        $this->assertStringNotContainsString('${d.door_id}: ${d.sync_status}', $render, 'Raw sync codes are no longer shown');
        $this->assertStringContainsString("'Belum pernah tersinkron'", $render);
    }

    // ---- notices ---------------------------------------------------------------------

    public function test_edit_form_and_access_modal_explain_what_saving_does(): void
    {
        $blade = $this->bladeSource();
        $this->assertStringContainsString('Perubahan ini hanya memperbarui data aplikasi. Sinkronisasi akses/perangkat dilakukan melalui <strong>Kelola Akses</strong>.', $blade);
        $this->assertStringContainsString('<div id="empAppOnlyNotice" role="note"', $blade);
        $this->assertStringNotContainsString('Perubahan akan langsung disinkronkan ke hardware', $blade);
        $this->assertStringContainsString('status baru menjadi <strong>Aktif di perangkat</strong> setelah perangkat mengonfirmasi', $blade);
        $this->assertStringContainsString('🚪 Kelola Akses', $this->functionBody('renderEmployeesTable'));
        $this->assertStringContainsString('Sinkronisasi ke perangkat dijadwalkan; status menjadi Aktif di perangkat setelah perangkat mengonfirmasi.', $this->functionBody('submitDoorAssignment'));
    }

    // ---- Kelola Akses detail -------------------------------------------------------------

    public function test_access_modal_shows_assignment_sync_reconciliation_and_timestamps(): void
    {
        $this->assertStringContainsString('${doorAccessDetail(assignment, reconciled)}', $this->functionBody('renderDoorAssignmentCheckboxes'));
        $this->assertStringContainsString('const reconciled = deviceDoors.find(item => Number(item.door_id) === Number(door.id));', $this->functionBody('renderDoorAssignmentCheckboxes'));

        $detail = $this->functionBody('doorAccessDetail');
        foreach (["row('Assignment aplikasi'", "row('Status sinkron'", "row('Rekonsiliasi perangkat'", "row('Terakhir sinkron'", "row('Terakhir diverifikasi'"] as $line) {
            $this->assertStringContainsString($line, $detail);
        }
        $this->assertStringContainsString("GRANTED: 'orang ada di perangkat',", $detail, 'Presence on the device is not presented as granted access');
        $this->assertStringContainsString("'Belum diverifikasi'", $detail);
    }

    // 51b4162: read-only reconciliation may set an assignment to synced from exact device
    // evidence without last_synced_at; the UI must not call that "never synced".
    public function test_synced_without_last_synced_at_is_shown_as_confirmed_by_reconciliation(): void
    {
        $render = $this->functionBody('renderEmployeesTable');
        $this->assertStringContainsString("(d.sync_status === 'synced' ? 'Dikonfirmasi dari rekonsiliasi perangkat (read-only)' : 'Belum pernah tersinkron')", $render);
        $this->assertStringContainsString("(assignment?.sync_status === 'synced' ? '— (dikonfirmasi dari rekonsiliasi perangkat)' : '—')", $this->functionBody('doorAccessDetail'));

        foreach (['normalized_identifier_candidate', 'ambiguous_normalized_identifier', 'duplicate_exact_raw_identifier', 'cards_point_to_different_employees'] as $reason) {
            $this->assertMatchesRegularExpression("/\\n    {$reason}: '[^']+',/", $this->script(), "Reason {$reason} has a label");
        }
    }
}
