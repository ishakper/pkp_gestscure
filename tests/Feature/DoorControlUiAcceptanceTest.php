<?php

namespace Tests\Feature;

use Tests\TestCase;

class DoorControlUiAcceptanceTest extends TestCase
{
    private string $blade;
    private string $script;

    protected function setUp(): void
    {
        parent::setUp();
        $this->blade = file_get_contents(resource_path('views/dashboard.blade.php'));
        $this->script = file_get_contents(public_path('js/dashboard.js'));
    }

    public function test_edit_door_has_canonical_and_legacy_support_with_cancel_and_validation(): void
    {
        // Modal structure and forms
        $this->assertStringContainsString('id="facilityModal"', $this->blade);
        $this->assertStringContainsString('id="facilityOriginalDoorId"', $this->blade);
        $this->assertStringContainsString('id="facilityDoorBuilding"', $this->blade);
        $this->assertStringContainsString('id="facilityDoorBuildingNotice"', $this->blade);
        $this->assertStringContainsString('id="facilityDoorCancel"', $this->blade);
        $this->assertStringContainsString("onclick=\"closeModal('facilityModal')\"", $this->blade);

        // Script handles canonical selection vs legacy notice
        $this->assertStringContainsString('function openFacilityModal(', $this->script);
        $this->assertStringContainsString('select.required = Boolean(door.building_id);', $this->script);
        $this->assertStringContainsString('Building legacy ambigu; pilih Building secara eksplisit', $this->script);
        $this->assertStringContainsString('function submitDoorConfig(', $this->script);
        $this->assertStringContainsString("window.openFacilityModal = openFacilityModal", $this->script);
    }

    public function test_diagnose_single_and_all_report_reasons_timestamps_and_partial_warnings(): void
    {
        // Single ping handles timestamp and explicit reason
        $this->assertStringContainsString('function pingSingleDoor(', $this->script);
        $this->assertStringContainsString('/check-connection', $this->script);
        $this->assertStringContainsString('res.diagnosis_reason', $this->script);
        $this->assertStringContainsString('last_checked_at', $this->script);

        // Check all connections reports partial summary and reasons
        $this->assertStringContainsString('function checkAllDoors(', $this->script);
        $this->assertStringContainsString('/check-all', $this->script);
        $this->assertStringContainsString('res.online_count === res.total_audited', $this->script);
        $this->assertStringContainsString("auditComplete ? 'Selesai' : 'Selesai dengan kegagalan'", $this->script);
        $this->assertStringContainsString('item.diagnosis_reason', $this->script);
    }

    public function test_maintenance_toggle_has_inflight_guard_loading_indicator_and_error_handling(): void
    {
        $this->assertStringContainsString('const pendingMaintenanceToggles = new Set();', $this->script);
        $this->assertStringContainsString('if (pendingMaintenanceToggles.has(doorId)) return;', $this->script);
        $this->assertStringContainsString("btn.innerHTML = `⏳ Menyimpan...`;", $this->script);
        $this->assertStringContainsString('pendingMaintenanceToggles.delete(doorId);', $this->script);
        $this->assertStringContainsString('window.toggleDoorStatus = toggleDoorStatus;', $this->script);
    }

    public function test_view_logs_navigates_filters_and_handles_empty_and_error_states(): void
    {
        $this->assertStringContainsString('function openDoorLogs(doorId)', $this->script);
        $this->assertStringContainsString("switchTab('logsTab')", $this->script);
        $this->assertStringContainsString('Tidak ada data log yang sesuai dengan filter', $this->script);
        $this->assertStringContainsString('Gagal memuat log akses:', $this->script);
    }

    public function test_remote_unlock_enforces_reason_prevents_duplicate_and_cleans_up_modal(): void
    {
        $this->assertStringContainsString('function openRemoteUnlockModal(', $this->script);
        $this->assertStringContainsString('function confirmRemoteUnlock(', $this->script);
        $this->assertStringContainsString('function cancelRemoteUnlock(', $this->script);
        $this->assertStringContainsString('Harap masukkan alasan pembukaan pintu', $this->script);
        $this->assertStringContainsString('button.disabled = true;', $this->script);
        $this->assertStringContainsString('button.textContent = \'⏳ Mengirim perintah...\';', $this->script);
        $this->assertStringContainsString('cancelRemoteUnlock();', $this->script);
        $this->assertStringContainsString('Remote unlock diblokir: Terminal', $this->script);
    }

    public function test_sync_users_and_provisioning_provides_batch_sync_and_single_employee_flows(): void
    {
        // Navigation from door card
        $this->assertStringContainsString('function openDoorUsers(doorId)', $this->script);
        $this->assertStringContainsString("switchTab('employeesTab')", $this->script);

        // Batch sync trigger in toolbar & function
        $this->assertStringContainsString('id="btnBatchSyncDoor"', $this->blade);
        $this->assertStringContainsString('function triggerBatchDoorSync(', $this->script);
        $this->assertStringContainsString('/admin/door-assignments/sync', $this->script);
        $this->assertStringContainsString('dispatched_count', $this->script);
        $this->assertStringContainsString('window.triggerBatchDoorSync = triggerBatchDoorSync', $this->script);

        // Single employee door provisioning function
        $this->assertStringContainsString('function syncSingleEmployeeDoor(', $this->script);
        $this->assertStringContainsString('/sync-employee/', $this->script);
        $this->assertStringContainsString('window.syncSingleEmployeeDoor = syncSingleEmployeeDoor', $this->script);
    }

    public function test_modal_focus_trap_escape_close_and_mobile_responsive_styling(): void
    {
        // Focus trap on Tab
        $this->assertStringContainsString("e.key === 'Tab' && activeModal", $this->script);
        $this->assertStringContainsString('modalFocusable(activeModal)', $this->script);

        // Escape closes top active modal
        $this->assertStringContainsString("e.key === 'Escape'", $this->script);
        $this->assertStringContainsString('closeModal(activeModal)', $this->script);

        // Scroll restoration
        $this->assertStringContainsString('window.scrollTo(0, modalScrollTop)', $this->script);
        $this->assertStringContainsString("document.body.classList.remove('modal-open')", $this->script);

        // Mobile CSS for door-actions and modal cards
        $this->assertStringContainsString('@media (max-width: 640px)', $this->blade);
        $this->assertStringContainsString('.door-actions { grid-template-columns: 1fr; }', $this->blade);
        $this->assertStringContainsString('.modal-card { max-width: calc(100vw - 2rem);', $this->blade);
    }
}
