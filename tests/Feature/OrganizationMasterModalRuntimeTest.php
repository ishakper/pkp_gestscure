<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Building;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Production 55f7598: super_admin opened Setup Gedung -> Divisi, clicked "+ Tambah Divisi"
 * and "Simpan", and no POST was sent. The modal was opened before the master data had
 * loaded, so it built an empty Gedung list, disabled Simpan and never refreshed once the
 * data arrived. These contracts pin the add -> modal -> submit -> POST -> refresh chain.
 */
class OrganizationMasterModalRuntimeTest extends TestCase
{
    use RefreshDatabase;

    private string $script;
    private string $blade;

    protected function setUp(): void
    {
        parent::setUp();
        $this->script = file_get_contents(public_path('js/dashboard.js'));
        $this->blade = file_get_contents(resource_path('views/dashboard.blade.php'));
    }

    private function functionBody(string $name): string
    {
        $this->assertSame(1, preg_match('/\n(?:async\s+)?function\s+' . preg_quote($name, '/') . '\s*\([^)]*\)\s*\{/', $this->script, $m, PREG_OFFSET_CAPTURE), "function {$name}() is not defined");
        $offset = $m[0][1];
        $next = preg_match('/\n(?:async\s+)?function\s+\w+\s*\(/', $this->script, $n, PREG_OFFSET_CAPTURE, $offset + 1) ? $n[0][1] : strlen($this->script);

        return substr($this->script, $offset, $next - $offset);
    }

    private function modalMarkup(): string
    {
        $start = strpos($this->blade, '<div class="modal-overlay" id="orgMasterModal"');
        $this->assertNotFalse($start, 'orgMasterModal markup is missing');
        $end = strpos($this->blade, '<!-- Configuration & Global Variables -->', $start);

        return substr($this->blade, $start, $end - $start);
    }

    public function test_add_buttons_open_the_modal_through_a_global_handler(): void
    {
        $this->assertStringContainsString('onclick="openOrganizationMasterModal(\'divisions\')"', $this->blade);
        $this->assertStringContainsString('onclick="openOrganizationMasterModal(\'positions\')"', $this->blade);
        // async function declarations inside the init guard are block-scoped; inline handlers need the export.
        $this->assertStringContainsString('window.openOrganizationMasterModal = openOrganizationMasterModal;', $this->script);
        $this->assertStringContainsString('window.submitOrganizationMaster = submitOrganizationMaster;', $this->script);
        $this->assertStringContainsString("openModal('orgMasterModal')", $this->functionBody('openOrganizationMasterModal'));
    }

    public function test_simpan_is_a_submit_button_inside_the_form_that_calls_the_handler(): void
    {
        $modal = $this->modalMarkup();

        $this->assertSame(1, substr_count($modal, '<form'), 'Exactly one form, no nesting');
        $this->assertSame(1, substr_count($modal, '</form>'));
        $this->assertStringContainsString('<form id="orgMasterForm" onsubmit="submitOrganizationMaster(event)"', $modal);
        $formBody = substr($modal, strpos($modal, '<form'), strpos($modal, '</form>') - strpos($modal, '<form'));
        $this->assertStringContainsString('<button type="submit" class="btn-primary" id="orgMasterSubmit">Simpan</button>', $formBody);
    }

    public function test_modal_element_ids_are_unique(): void
    {
        foreach (['orgMasterModal', 'orgMasterForm', 'orgMasterType', 'orgMasterId', 'orgMasterParent', 'orgMasterCode', 'orgMasterName', 'orgMasterActive', 'orgMasterError', 'orgMasterSubmit', 'divisionsTableBody', 'positionsTableBody'] as $id) {
            $this->assertSame(1, substr_count($this->blade, 'id="' . $id . '"'), "id=\"{$id}\" must appear exactly once");
        }
    }

    public function test_modal_waits_for_master_data_before_building_parents(): void
    {
        $open = $this->functionBody('openOrganizationMasterModal');

        $this->assertStringContainsString('if (!organizationMaster.loaded) {', $open);
        $this->assertStringContainsString('Memuat data ${isDivision ? \'gedung\' : \'divisi\'}...', $open);
        $this->assertStringContainsString('await loadOrganizationMaster();', $open);
        $this->assertStringContainsString('if (seq !== organizationMasterModalSeq) return;', $open);
        $this->assertStringContainsString("showOrganizationMasterError('Data gedung/divisi gagal dimuat. Tutup lalu coba lagi.')", $open);
        // Parents and the Simpan state are decided only from loaded data.
        $this->assertLessThan(strpos($open, 'fillOrganizationMasterModal(type, id)'), strpos($open, 'await loadOrganizationMaster();'));

        $fill = $this->functionBody('fillOrganizationMasterModal');
        $this->assertStringContainsString('submit.disabled = parents.length === 0;', $fill);
        $this->assertStringContainsString('parent.disabled = false;', $fill);
        $this->assertStringContainsString('if (item) { // create keeps whatever was typed while the data was loading', $fill);
    }

    public function test_submit_posts_to_the_existing_endpoint_and_refreshes(): void
    {
        $submit = $this->functionBody('submitOrganizationMaster');

        $this->assertStringContainsString('event.preventDefault();', $submit);
        $this->assertStringContainsString('await apiFetch(`/user-management/organization/${type}${id ? `/${Number(id)}` : \'\'}`, {', $submit);
        $this->assertStringContainsString("method: id ? 'PUT' : 'POST',", $submit);
        $this->assertStringContainsString('invalidateOrganizationLookup();', $submit);
        $this->assertStringContainsString('await refreshOrganizationMasterList(type);', $submit);
        $this->assertStringContainsString('submit.disabled = false;', $submit, 'Simpan is re-enabled after every attempt');
    }

    public function test_the_post_the_modal_sends_creates_the_division(): void
    {
        Sanctum::actingAs(Admin::factory()->create(['role' => 'super_admin']));
        $building = Building::create(['code' => 'BLD-B', 'name' => 'Gedung B', 'is_active' => true]);

        // Same payload shape submitOrganizationMaster() sends for a new division.
        $this->postJson('/api/v1/user-management/organization/divisions', ['code' => 'DIV-IT', 'name' => 'IT', 'building_id' => $building->id])
            ->assertCreated()->assertJsonPath('data.building_id', $building->id);
        $this->assertSame(['IT'], array_column($this->getJson('/api/v1/user-management/organization/divisions')->json('data'), 'name'));
        $this->assertSame(['IT'], array_column($this->getJson('/api/v1/user-management/organization/lookup')->json('data.divisions'), 'name'));
    }
}
