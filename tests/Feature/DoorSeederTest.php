<?php

namespace Tests\Feature;

use App\Models\Door;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DoorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DoorSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_sets_door_b_ip_to_192_168_90_15_with_gateway(): void
    {
        $this->seed(DatabaseSeeder::class);

        $doorB = Door::where('door_id', 'DOOR-B')->first();

        $this->assertNotNull($doorB);
        $this->assertEquals('192.168.90.15', $doorB->device_ip);
        $this->assertEquals('192.168.90.15', $doorB->ip_address);
        $this->assertEquals('192.168.90.1', $doorB->gateway);
        $this->assertEquals('Gedung B (IT & Infra)', $doorB->location);
    }

    public function test_door_seeder_sets_door_b_ip_and_gateway_correctly(): void
    {
        $this->seed(DoorSeeder::class);

        $doorB = Door::where('door_id', 'DOOR-B')->first();

        $this->assertNotNull($doorB);
        $this->assertEquals('192.168.90.15', $doorB->device_ip);
        $this->assertEquals('192.168.90.15', $doorB->ip_address);
        $this->assertEquals('192.168.90.1', $doorB->gateway);
    }
}
