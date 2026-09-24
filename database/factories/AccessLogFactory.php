<?php

namespace Database\Factories;

use App\Models\AccessLog;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccessLogFactory extends Factory
{
    protected $model = AccessLog::class;

    public function definition(): array
    {
        return [
            'log_id' => $this->faker->unique()->uuid(),
            'door_id' => 1,
            'nik' => $this->faker->numerify('NIK-########'),
            'event_type' => 'STANDARD_TAP',
            'status' => 'Granted',
            'access_status' => 'Granted',
            'scanned_at' => $this->faker->dateTime(),
            'timestamp' => $this->faker->dateTime(),
        ];
    }
}
