<?php
namespace Tests\Feature;
use App\Models\Admin;
use App\Services\PortalAccess;
use Tests\TestCase;
class PortalAccessTest extends TestCase {
 public function test_legacy_roles_map_to_expected_portals(): void { $access=app(PortalAccess::class); $this->assertSame(PortalAccess::ADMIN_PORTAL,$access->portalFor(new Admin(['role'=>'super_admin']))); $this->assertSame(PortalAccess::MANAGEMENT_PORTAL,$access->portalFor(new Admin(['role'=>'building_admin']))); $this->assertSame(PortalAccess::EMPLOYEE_PORTAL,$access->portalFor(new Admin(['role'=>'employee']))); }
 public function test_building_admin_does_not_receive_device_management_permission(): void { $access=app(PortalAccess::class); $admin=new Admin(['role'=>'building_admin']); $this->assertTrue($access->can($admin,'employee.manage')); $this->assertFalse($access->can($admin,'device.manage')); }
}
