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
                'ip_address' => config('services.doors.DOOR-A.ip', '192.168.90.11'),
                'device_ip' => config('services.doors.DOOR-A.ip', '192.168.90.11'),
                'gateway' => config('services.doors.DOOR-A.gateway', '192.168.90.1'),
                'model' => 'DS-K1T804AMF',
                'device_model' => 'DS-K1T804AMF',
                'status' => 'offline',
                'connection_status' => 'offline',
                'is_manual_override' => false,
                'last_checked_at' => now(),
            ],
            [
                'door_id' => 'DOOR-B',
                'name' => 'Door B - Restricted Server Room',
                'door_name' => 'Door B - Restricted Server Room',
                'location' => 'Gedung B (IT & Infra)',
                'ip_address' => config('services.doors.DOOR-B.ip', '192.168.90.15'),
                'device_ip' => config('services.doors.DOOR-B.ip', '192.168.90.15'),
                'gateway' => config('services.doors.DOOR-B.gateway', '192.168.90.1'),
                'model' => 'DS-K1T804AMF',
                'device_model' => 'DS-K1T804AMF',
                'status' => 'offline',
                'connection_status' => 'offline',
                'is_manual_override' => false,
                'last_checked_at' => now(),
            ],
            [
                'door_id' => 'DOOR-C',
                'name' => 'Door C - Akses Operasional Lapangan',
                'door_name' => 'Door C - Akses Operasional Lapangan',
                'location' => 'Gedung C (Operasional)',
                'ip_address' => config('services.doors.DOOR-C.ip', '192.168.90.13'),
                'device_ip' => config('services.doors.DOOR-C.ip', '192.168.90.13'),
                'gateway' => config('services.doors.DOOR-C.gateway', '192.168.90.1'),
                'model' => 'DS-K1T804AMF',
                'device_model' => 'DS-K1T804AMF',
                'status' => 'offline',
                'connection_status' => 'offline',
                'is_manual_override' => false,
                'last_checked_at' => now(),
            ],
            [
                'door_id' => 'DOOR-D',
                'name' => 'Door D - Akses Pabrik & Produksi',
                'door_name' => 'Door D - Akses Pabrik & Produksi',
                'location' => 'Gedung D (Produksi)',
                'ip_address' => config('services.doors.DOOR-D.ip', '192.168.90.14'),
                'device_ip' => config('services.doors.DOOR-D.ip', '192.168.90.14'),
                'gateway' => config('services.doors.DOOR-D.gateway', '192.168.90.1'),
                'model' => 'DS-K1T804AMF',
                'device_model' => 'DS-K1T804AMF',
                'status' => 'offline',
                'connection_status' => 'offline',
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
