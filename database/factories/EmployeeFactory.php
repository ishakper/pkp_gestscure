<?php

namespace Database\Factories;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'employee_id' => fake()->unique()->bothify('USR-####'),
            'nik' => fake()->unique()->bothify('NIK-########'),
            'name' => fake()->name(),
            'card_no' => null,
            'department' => fake()->word(),
            'role' => 'Staff',
            'role_jabatan' => 'Staff',
            'employment_status' => 'ACTIVE',
        ];
    }
}
