<?php
namespace Tests\Feature;use App\Models\Admin;use Illuminate\Foundation\Testing\RefreshDatabase;use Tests\TestCase;
class AdminRoleValidationTest extends TestCase {use RefreshDatabase;public function test_invalid_admin_role_is_rejected_by_application_validation():void{$this->expectException(\InvalidArgumentException::class);Admin::create(['name'=>'x','email'=>'x@test.local','password'=>'x','role'=>'invalid_role']);}}

