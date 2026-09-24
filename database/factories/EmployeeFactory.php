<?php

namespace Database\Factories;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        $sequence = $this->faker->unique()->numberBetween(1, 99999999);

        return [
            'employee_id' => sprintf('USR-%08d', $sequence),
            'hikvision_employee_no' => sprintf('USR-%08d', $sequence),
            'nik' => sprintf('NIK-%08d', $sequence),
            'name' => $this->faker->name(),
            'card_no' => null,
            'department' => 'Belum Ditentukan',
            'role' => 'Staff',
            'role_jabatan' => 'Staff',
            'employment_status' => 'ACTIVE',
            'credential_method' => 'unknown',
            'credential_status' => 'unknown',
            'credential_source' => null,
            'card_registered' => false,
            'card_count' => 0,
            'card_type' => null,
            'fingerprint_verified' => false,
        ];
    }

    public function withoutEmploymentStatus(): static
    {
        return $this->state(fn (array $attributes) => [
            'employment_status' => 'ACTIVE', // Force to ACTIVE if nullable
        ]);
    }
}
