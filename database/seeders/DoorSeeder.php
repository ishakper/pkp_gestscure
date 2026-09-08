<?php

namespace Database\Seeders;

use App\Models\Door;
use Illuminate\Database\Seeder;

class DoorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $doors = [
            [
                'door_id' => 'DOOR-A',
                'name' => 'Door A - Akses Pegawai & Tamu',
                'door_name' => 'Door A - Akses Pegawai & Tamu',
                'location' => 'Gedung A (Kantor Utama)',
                'ip_address' => env('DOOR_A_IP', '192.168.90.11'),
                'device_ip' => env('DOOR_A_IP', '192.168.90.11'),
                'gateway' => env('DOOR_A_GATEWAY', '192.168.90.1'),
                'model' => 'DS-K1T804AMF',
                'device_model' => 'DS-K1T804AMF',
                'status' => 'online',
                'connection_status' => 'online',
                'is_manual_override' => false,
                'last_checked_at' => now(),
            ],
            [
                'door_id' => 'DOOR-B',
                'name' => 'Door B - Restricted Server Room',
                'door_name' => 'Door B - Restricted Server Room',
                'location' => 'Gedung B (IT & Infra)',
                'ip_address' => env('DOOR_B_IP', '192.168.90.15'),
                'device_ip' => env('DOOR_B_IP', '192.168.90.15'),
                'gateway' => env('DOOR_B_GATEWAY', '192.168.90.1'),
                'model' => 'DS-K1T804AMF',
                'device_model' => 'DS-K1T804AMF',
                'status' => 'online',
                'connection_status' => 'online',
                'is_manual_override' => false,
                'last_checked_at' => now(),
            ],
            [
                'door_id' => 'DOOR-C',
                'name' => 'Door C - Akses Operasional Lapangan',
                'door_name' => 'Door C - Akses Operasional Lapangan',
                'location' => 'Gedung C (Operasional)',
                'ip_address' => env('DOOR_C_IP', '192.168.90.13'),
                'device_ip' => env('DOOR_C_IP', '192.168.90.13'),
                'gateway' => env('DOOR_C_GATEWAY', '192.168.90.1'),
                'model' => 'DS-K1T804AMF',
                'device_model' => 'DS-K1T804AMF',
                'status' => 'online',
                'connection_status' => 'online',
                'is_manual_override' => false,
                'last_checked_at' => now(),
            ],
            [
                'door_id' => 'DOOR-D',
                'name' => 'Door D - Akses Pabrik & Produksi',
                'door_name' => 'Door D - Akses Pabrik & Produksi',
                'location' => 'Gedung D (Produksi)',
                'ip_address' => env('DOOR_D_IP', '192.168.90.14'),
                'device_ip' => env('DOOR_D_IP', '192.168.90.14'),
                'gateway' => env('DOOR_D_GATEWAY', '192.168.90.1'),
                'model' => 'DS-K1T804AMF',
                'device_model' => 'DS-K1T804AMF',
                'status' => 'online',
                'connection_status' => 'online',
                'is_manual_override' => false,
                'last_checked_at' => now(),
            ],
        ];

        foreach ($doors as $doorData) {
            Door::updateOrCreate(
                ['door_id' => $doorData['door_id']],
                $doorData
            );
        }
    }
}
