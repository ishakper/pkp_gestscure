<?php

namespace Database\Seeders;

use App\Models\AccessLog;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\BiometricStatus;
use App\Models\Building;
use App\Models\Division;
use App\Models\Position;
use App\Models\Zone;
use App\Models\Door;
use App\Models\DoorAssignment;
use App\Models\Employee;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 0. Seed Building Hierarchy (NEW)
        $buildingA = Building::firstOrCreate(['code' => 'BLD-A'], [
            'name' => 'Gedung A (Kantor Utama)',
            'description' => 'Main Office Building',
            'is_active' => true,
        ]);

        $buildingB = Building::firstOrCreate(['code' => 'BLD-B'], [
            'name' => 'Gedung B (IT & Infra)',
            'description' => 'IT Infrastructure and Data Center',
            'is_active' => true,
        ]);

        $buildingC = Building::firstOrCreate(['code' => 'BLD-C'], [
            'name' => 'Gedung C (Operasional)',
            'description' => 'Operations and Logistics',
            'is_active' => true,
        ]);

        $buildingD = Building::firstOrCreate(['code' => 'BLD-D'], [
            'name' => 'Gedung D (Produksi)',
            'description' => 'Manufacturing and Production',
            'is_active' => true,
        ]);

        // Seed Divisions per Building
        $divIT = Division::firstOrCreate(['code' => 'DIV-IT'], [
            'building_id' => $buildingB->id,
            'name' => 'IT Support',
            'is_active' => true,
        ]);

        $divHR = Division::firstOrCreate(['code' => 'DIV-HR'], [
            'building_id' => $buildingA->id,
            'name' => 'HR & Admin',
            'is_active' => true,
        ]);

        $divOps = Division::firstOrCreate(['code' => 'DIV-OPS'], [
            'building_id' => $buildingC->id,
            'name' => 'Operasional',
            'is_active' => true,
        ]);

        $divProd = Division::firstOrCreate(['code' => 'DIV-PROD'], [
            'building_id' => $buildingD->id,
            'name' => 'Produksi',
            'is_active' => true,
        ]);

        $divExec = Division::firstOrCreate(['code' => 'DIV-EXEC'], [
            'building_id' => $buildingA->id,
            'name' => 'Executive',
            'is_active' => true,
        ]);

        // Seed Positions
        Position::firstOrCreate(['code' => 'POS-LEAD'], ['division_id' => $divIT->id, 'name' => 'Lead Infrastructure', 'is_active' => true]);
        Position::firstOrCreate(['code' => 'POS-HR-MGR'], ['division_id' => $divHR->id, 'name' => 'HR Manager', 'is_active' => true]);
        Position::firstOrCreate(['code' => 'POS-SUP-OPS'], ['division_id' => $divOps->id, 'name' => 'Supervisor Operasional', 'is_active' => true]);
        Position::firstOrCreate(['code' => 'POS-QC'], ['division_id' => $divProd->id, 'name' => 'Quality Control Specialist', 'is_active' => true]);
        Position::firstOrCreate(['code' => 'POS-DEVOPS'], ['division_id' => $divIT->id, 'name' => 'DevOps Engineer', 'is_active' => true]);
        Position::firstOrCreate(['code' => 'POS-LOGISTICS'], ['division_id' => $divOps->id, 'name' => 'Staff Logistik', 'is_active' => true]);
        Position::firstOrCreate(['code' => 'POS-HEAD-PROD'], ['division_id' => $divProd->id, 'name' => 'Head of Production', 'is_active' => true]);
        Position::firstOrCreate(['code' => 'POS-GA'], ['division_id' => $divHR->id, 'name' => 'Staff General Affairs', 'is_active' => true]);
        Position::firstOrCreate(['code' => 'POS-SEC'], ['division_id' => $divIT->id, 'name' => 'System Security Analyst', 'is_active' => true]);
        Position::firstOrCreate(['code' => 'POS-TECH-MAINT'], ['division_id' => $divProd->id, 'name' => 'Technician Maintenance', 'is_active' => true]);
        Position::firstOrCreate(['code' => 'POS-DISPATCH'], ['division_id' => $divOps->id, 'name' => 'Dispatch Supervisor', 'is_active' => true]);
        Position::firstOrCreate(['code' => 'POS-GM'], ['division_id' => $divExec->id, 'name' => 'General Manager', 'is_active' => true]);

        // Seed Zones per Building
        Zone::firstOrCreate(['building_id' => $buildingA->id, 'code' => 'ZONE-A1'], ['name' => 'Main Entrance', 'is_active' => true]);
        Zone::firstOrCreate(['building_id' => $buildingB->id, 'code' => 'ZONE-B1'], ['name' => 'Server Room', 'is_active' => true]);
        Zone::firstOrCreate(['building_id' => $buildingC->id, 'code' => 'ZONE-C1'], ['name' => 'Operations Center', 'is_active' => true]);
        Zone::firstOrCreate(['building_id' => $buildingD->id, 'code' => 'ZONE-D1'], ['name' => 'Production Floor', 'is_active' => true]);

        // 1. Seed Admins
        $superAdmin = Admin::create([
            'name' => 'Super Administrator',
            'email' => 'admin@accesscontrol.local',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
            'assigned_building' => null,
        ]);

        $buildingAdmin = Admin::create([
            'name' => 'Admin Gedung A',
            'email' => 'admin.gedunga@accesscontrol.local',
            'password' => Hash::make('password'),
            'role' => 'building_admin',
            'assigned_building' => 'Gedung A (Kantor Utama)',
        ]);

        // 2. Seed 4 Physical Doors (now with building_id & zone_id FK)
        $zoneA1 = Zone::where('code', 'ZONE-A1')->first();
        $zoneB1 = Zone::where('code', 'ZONE-B1')->first();
        $zoneC1 = Zone::where('code', 'ZONE-C1')->first();
        $zoneD1 = Zone::where('code', 'ZONE-D1')->first();

        $doorA = Door::firstOrCreate(['door_id' => 'DOOR-A'], [
            'name' => 'Door A - Akses Pegawai & Tamu',
            'door_name' => 'Door A - Akses Pegawai & Tamu',
            'location' => 'Gedung A (Kantor Utama)',
            'building_id' => $buildingA->id,
            'zone_id' => $zoneA1?->id,
            'ip_address' => config('services.doors.DOOR-A.ip', '192.168.90.11'),
            'device_ip' => config('services.doors.DOOR-A.ip', '192.168.90.11'),
            'gateway' => config('services.doors.DOOR-A.gateway', '192.168.90.1'),
            'model' => 'DS-K1T804AMF',
            'device_model' => 'DS-K1T804AMF',
            'status' => 'online',
            'connection_status' => 'online',
            'is_manual_override' => false,
            'last_checked_at' => now(),
        ]);

        $doorB = Door::firstOrCreate(['door_id' => 'DOOR-B'], [
            'name' => 'Door B - Restricted Server Room',
            'door_name' => 'Door B - Restricted Server Room',
            'location' => 'Gedung B (IT & Infra)',
            'building_id' => $buildingB->id,
            'zone_id' => $zoneB1?->id,
            'ip_address' => config('services.doors.DOOR-B.ip', '192.168.90.15'),
            'device_ip' => config('services.doors.DOOR-B.ip', '192.168.90.15'),
            'gateway' => config('services.doors.DOOR-B.gateway', '192.168.90.1'),
            'model' => 'DS-K1T804AMF',
            'device_model' => 'DS-K1T804AMF',
            'status' => 'online',
            'connection_status' => 'online',
            'is_manual_override' => false,
            'last_checked_at' => now(),
        ]);

        $doorC = Door::firstOrCreate(['door_id' => 'DOOR-C'], [
            'name' => 'Door C - Akses Operasional Lapangan',
            'door_name' => 'Door C - Akses Operasional Lapangan',
            'location' => 'Gedung C (Operasional)',
            'building_id' => $buildingC->id,
            'zone_id' => $zoneC1?->id,
            'ip_address' => config('services.doors.DOOR-C.ip', '192.168.90.13'),
            'device_ip' => config('services.doors.DOOR-C.ip', '192.168.90.13'),
            'gateway' => config('services.doors.DOOR-C.gateway', '192.168.90.1'),
            'model' => 'DS-K1T804AMF',
            'device_model' => 'DS-K1T804AMF',
            'status' => 'online',
            'connection_status' => 'online',
            'is_manual_override' => false,
            'last_checked_at' => now(),
        ]);

        $doorD = Door::firstOrCreate(['door_id' => 'DOOR-D'], [
            'name' => 'Door D - Akses Pabrik & Produksi',
            'door_name' => 'Door D - Akses Pabrik & Produksi',
            'location' => 'Gedung D (Produksi)',
            'building_id' => $buildingD->id,
            'zone_id' => $zoneD1?->id,
            'ip_address' => config('services.doors.DOOR-D.ip', '192.168.90.14'),
            'device_ip' => config('services.doors.DOOR-D.ip', '192.168.90.14'),
            'gateway' => config('services.doors.DOOR-D.gateway', '192.168.90.1'),
            'model' => 'DS-K1T804AMF',
            'device_model' => 'DS-K1T804AMF',
            'status' => 'online',
            'connection_status' => 'online',
            'is_manual_override' => false,
            'last_checked_at' => now(),
        ]);

        $doors = [$doorA, $doorB, $doorC, $doorD];

        // 3. Seed Employees
        $employeeData = [
            ['employee_id' => 'USR-1001', 'nik' => 'NIK-882101', 'name' => 'Budi Santoso', 'building' => $buildingB, 'department' => 'IT Support', 'role_jabatan' => 'Lead Infrastructure', 'card_no' => 'CARD-882101', 'fp' => true, 'card' => true, 'assigned' => [$doorA, $doorB]],
            ['employee_id' => 'USR-1002', 'nik' => 'NIK-882102', 'name' => 'Siti Rahma', 'building' => $buildingA, 'department' => 'HR & Admin', 'role_jabatan' => 'HR Manager', 'card_no' => 'CARD-882102', 'fp' => true, 'card' => true, 'assigned' => [$doorA]],
            ['employee_id' => 'USR-1003', 'nik' => 'NIK-882103', 'name' => 'Agus Setiawan', 'building' => $buildingC, 'department' => 'Operasional', 'role_jabatan' => 'Supervisor Operasional', 'card_no' => null, 'fp' => true, 'card' => false, 'assigned' => [$doorA, $doorC]],
            ['employee_id' => 'USR-1004', 'nik' => 'NIK-882104', 'name' => 'Dewi Lestari', 'building' => $buildingD, 'department' => 'Produksi', 'role_jabatan' => 'Quality Control Specialist', 'card_no' => 'CARD-882104', 'fp' => true, 'card' => true, 'assigned' => [$doorA, $doorD]],
            ['employee_id' => 'USR-1005', 'nik' => 'NIK-882105', 'name' => 'Eko Prasetyo', 'building' => $buildingB, 'department' => 'IT Support', 'role_jabatan' => 'DevOps Engineer', 'card_no' => 'CARD-882105', 'fp' => true, 'card' => true, 'assigned' => [$doorA, $doorB]],
            ['employee_id' => 'USR-1006', 'nik' => 'NIK-882106', 'name' => 'Rina Permata', 'building' => $buildingC, 'department' => 'Operasional', 'role_jabatan' => 'Staff Logistik', 'card_no' => 'CARD-882106', 'fp' => false, 'card' => true, 'assigned' => [$doorC]],
            ['employee_id' => 'USR-1007', 'nik' => 'NIK-882107', 'name' => 'Hendra Wijaya', 'building' => $buildingD, 'department' => 'Produksi', 'role_jabatan' => 'Head of Production', 'card_no' => 'CARD-882107', 'fp' => true, 'card' => true, 'assigned' => [$doorA, $doorD]],
            ['employee_id' => 'USR-1008', 'nik' => 'NIK-882108', 'name' => 'Maya Kusuma', 'building' => $buildingA, 'department' => 'HR & Admin', 'role_jabatan' => 'Staff General Affairs', 'card_no' => null, 'fp' => true, 'card' => false, 'assigned' => [$doorA]],
            ['employee_id' => 'USR-1009', 'nik' => 'NIK-882109', 'name' => 'Rudi Hermawan', 'building' => $buildingB, 'department' => 'IT Support', 'role_jabatan' => 'System Security Analyst', 'card_no' => 'CARD-882109', 'fp' => true, 'card' => true, 'assigned' => [$doorA, $doorB, $doorC]],
            ['employee_id' => 'USR-1010', 'nik' => 'NIK-882110', 'name' => 'Ahmad Fauzi', 'building' => $buildingD, 'department' => 'Produksi', 'role_jabatan' => 'Technician Maintenance', 'card_no' => null, 'fp' => true, 'card' => false, 'assigned' => [$doorD]],
            ['employee_id' => 'USR-1011', 'nik' => 'NIK-882111', 'name' => 'Nina Marlina', 'building' => $buildingC, 'department' => 'Operasional', 'role_jabatan' => 'Dispatch Supervisor', 'card_no' => 'CARD-882111', 'fp' => true, 'card' => true, 'assigned' => [$doorC]],
            ['employee_id' => 'USR-1012', 'nik' => 'NIK-882112', 'name' => 'Bambang Hartono', 'building' => $buildingA, 'department' => 'Executive', 'role_jabatan' => 'General Manager', 'card_no' => 'CARD-882112', 'fp' => true, 'card' => true, 'assigned' => [$doorA, $doorB, $doorC, $doorD]],
        ];

        $createdEmployees = [];

        foreach ($employeeData as $index => $emp) {
            $employee = Employee::firstOrCreate(['nik' => $emp['nik']], [
                'employee_id' => $emp['employee_id'],
                'name' => $emp['name'],
                'card_no' => $emp['card_no'],
                'department' => $emp['department'],
                'building_id' => $emp['building']->id,
                'role' => $emp['role_jabatan'],
                'role_jabatan' => $emp['role_jabatan'],
            ]);

            BiometricStatus::create([
                'employee_id' => $employee->id,
                'has_fingerprint' => $emp['fp'],
                'fingerprint_enrolled' => $emp['fp'],
                'card_enrolled' => $emp['card'],
                'biometric_template' => $emp['fp'] ? 'BASE64_MOCK_FINGERPRINT_TEMPLATE_' . $emp['nik'] : null,
            ]);

            // Create Door Assignments
            foreach ($emp['assigned'] as $dIndex => $assignedDoor) {
                // Introduce status variation: mostly synced, some pending, 1 failed for testing retry
                $syncStatus = 'synced';
                $attempts = 1;
                $syncError = null;

                if ($index === 3 && $dIndex === 1) {
                    $syncStatus = 'failed';
                    $attempts = 3;
                    $syncError = 'Connection timeout to ' . $assignedDoor->device_ip . ': ISAPI 503 Service Unavailable';
                } elseif ($index === 5) {
                    $syncStatus = 'pending';
                    $attempts = 0;
                }

                DoorAssignment::create([
                    'employee_id' => $employee->id,
                    'door_id' => $assignedDoor->id,
                    'sync_status' => $syncStatus,
                    'sync_attempts' => $attempts,
                    'last_sync_error' => $syncError,
                    'last_synced_at' => $syncStatus === 'synced' ? now()->subMinutes(rand(10, 300)) : null,
                ]);
            }

            $createdEmployees[] = $employee;
        }

        // 4. Seed Access Logs (~20 realistic entries)
        $verifyMethods = ['Fingerprint', 'Card'];
        $accessStatuses = ['Granted', 'Granted', 'Granted', 'Granted', 'Denied'];

        for ($i = 1; $i <= 20; $i++) {
            $door = $doors[array_rand($doors)];
            $employee = $createdEmployees[array_rand($createdEmployees)];
            $status = $accessStatuses[array_rand($accessStatuses)];
            $method = $verifyMethods[array_rand($verifyMethods)];
            $isUnregistered = ($status === 'Denied' && $i % 4 === 0);
            $reason = $status === 'Denied' ? ($isUnregistered ? 'Unregistered Card Tap' : 'Access Time Window Unauthorized') : null;

            AccessLog::create([
                'log_id' => 'LOG-' . date('Ymd') . '-' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'door_id' => $door->id,
                'employee_id' => $isUnregistered ? null : $employee->id,
                'nik' => $isUnregistered ? 'UNKNOWN-' . rand(1000, 9999) : $employee->nik,
                'device_ip' => $door->device_ip,
                'auth_method' => $method,
                'verify_method' => $method,
                'status' => $status,
                'access_status' => $status,
                'reason' => $reason,
                'scanned_at' => now()->subMinutes(rand(5, 1440)),
                'timestamp' => now()->subMinutes(rand(5, 1440)),
            ]);
        }

        // 5. Seed Activity Logs
        ActivityLog::create([
            'admin_id' => $superAdmin->id,
            'action' => 'system_initialized',
            'description' => 'Access Control System initialized with 4 physical doors and default employee records.',
            'timestamp' => now()->subHours(24),
        ]);

        ActivityLog::create([
            'admin_id' => $superAdmin->id,
            'action' => 'assign_door_access',
            'description' => 'Assigned DOOR-B (IT & Infra) access right to Budi Santoso (USR-1001)',
            'timestamp' => now()->subHours(5),
        ]);

        // 6. Seed Recruitment Stages
        $this->call(RecruitmentStageSeeder::class);
    }
}
