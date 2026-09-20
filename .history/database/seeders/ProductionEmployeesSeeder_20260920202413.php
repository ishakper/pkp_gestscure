<?php

namespace Database\Seeders;

use App\Models\BiometricStatus;
use App\Models\Building;
use App\Models\Employee;
use Illuminate\Database\Seeder;

/**
 * Production Employee Data Seeder
 *
 * Generates realistic production employee dataset matching business requirements:
 * - 96 ACTIVE employees
 * - 82 with card enrollment
 * - 14 without card enrollment
 *
 * USAGE:
 *   php artisan db:seed --class=ProductionEmployeesSeeder
 *
 * This seeder generates production data only and does NOT create demo buildings.
 * It assumes Building hierarchy already exists from DatabaseSeeder.
 *
 * DO NOT CALL THIS automatically in DatabaseSeeder (preserve dev/demo path).
 * DO CALL THIS during first production deployment setup.
 */
class ProductionEmployeesSeeder extends Seeder
{
    public function run(): void
    {
        // Get existing buildings or use primary building if minimal setup
        $primaryBuilding = Building::firstOrCreate(
            ['code' => 'BLD-PRIMARY'],
            ['name' => 'Primary Building', 'is_active' => true]
        );

        // Generate 96 active employees
        // Distribution: 82 with cards, 14 without cards
        $cardUsers = 82;
        $noCardUsers = 14;
        $totalActive = $cardUsers + $noCardUsers;

        // Employee name pools for realistic data
        $firstNames = ['Budi', 'Siti', 'Agus', 'Dewi', 'Eko', 'Rina', 'Hendra', 'Maya', 'Rinto', 'Lina',
            'Bambang', 'Nur', 'Hendri', 'Ayu', 'Doni', 'Tina', 'Rudi', 'Sinta', 'Arif', 'Dina'];
        $lastNames = ['Santoso', 'Rahma', 'Setiawan', 'Lestari', 'Prasetyo', 'Permata', 'Wijaya', 'Kusuma',
            'Gunawan', 'Hidayat', 'Suryanto', 'Halim', 'Junaedi', 'Seorang', 'Mahasiswa', 'Kerja', 'Industri'];

        $departments = ['IT Support', 'HR & Admin', 'Operasional', 'Produksi', 'Finance', 'Marketing', 'Sales',
            'Quality Control', 'Logistics', 'Maintenance'];
        $roles = ['Lead', 'Manager', 'Supervisor', 'Specialist', 'Staff', 'Officer', 'Analyst', 'Engineer'];

        // Card users (1-82): with valid card numbers
        for ($i = 1; $i <= $cardUsers; $i++) {
            $firstName = $firstNames[array_rand($firstNames)];
            $lastName = $lastNames[array_rand($lastNames)];

            $employee = Employee::firstOrCreate(
                ['employee_id' => sprintf('EMP-%05d', $i)],
                [
                    'nik' => sprintf('NIK-%08d', 10000000 + $i),
                    'name' => "{$firstName} {$lastName}",
                    'email' => strtolower("{$firstName}.{$lastName}@company.local"),
                    'phone' => sprintf('+62-8%d-%08d', rand(1, 9), rand(10000000, 99999999)),
                    'building_id' => $primaryBuilding->id,
                    'department' => $departments[array_rand($departments)],
                    'role_jabatan' => $roles[array_rand($roles)],
                    'role' => $roles[array_rand($roles)],
                    'employment_status' => 'ACTIVE',
                    'employment_type' => 'PERMANENT',
                    'hire_date' => now()->subMonths(rand(6, 60))->toDateString(),
                    'card_no' => sprintf('CARD-%08d', 10000000 + $i),
                    'card_registered' => 'YES',
                ]
            );

            // Register biometric status
            BiometricStatus::firstOrCreate(
                ['employee_id' => $employee->id],
                [
                    'has_fingerprint' => rand(0, 1) === 1,
                    'fingerprint_enrolled' => rand(0, 1) === 1,
                    'card_enrolled' => true,
                    'biometric_template' => null,
                ]
            );
        }

        // No-card users (83-96): without card enrollment
        for ($i = 83; $i <= $totalActive; $i++) {
            $firstName = $firstNames[array_rand($firstNames)];
            $lastName = $lastNames[array_rand($lastNames)];

            $employee = Employee::firstOrCreate(
                ['employee_id' => sprintf('EMP-%05d', $i)],
                [
                    'nik' => sprintf('NIK-%08d', 10000000 + $i),
                    'name' => "{$firstName} {$lastName}",
                    'email' => strtolower("{$firstName}.{$lastName}@company.local"),
                    'phone' => sprintf('+62-8%d-%08d', rand(1, 9), rand(10000000, 99999999)),
                    'building_id' => $primaryBuilding->id,
                    'department' => $departments[array_rand($departments)],
                    'role_jabatan' => $roles[array_rand($roles)],
                    'role' => $roles[array_rand($roles)],
                    'employment_status' => 'ACTIVE',
                    'employment_type' => 'PERMANENT',
                    'hire_date' => now()->subMonths(rand(6, 60))->toDateString(),
                    'card_no' => null,
                    'card_registered' => 'NO',
                ]
            );

            // Register biometric status (fingerprint only, no card)
            BiometricStatus::firstOrCreate(
                ['employee_id' => $employee->id],
                [
                    'has_fingerprint' => true,
                    'fingerprint_enrolled' => true,
                    'card_enrolled' => false,
                    'biometric_template' => null,
                ]
            );
        }

        $this->command->info("✅ Seeded {$totalActive} production employees: {$cardUsers} with cards, {$noCardUsers} without cards.");
    }
}
