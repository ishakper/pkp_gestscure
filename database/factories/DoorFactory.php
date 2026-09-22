<?php

namespace Database\Factories;

use App\Models\Door;
use App\Models\Building;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Door>
 */
class DoorFactory extends Factory
{
    /**
     * The model the factory corresponds to.
     *
     * @var string
     */
    protected $model = Door::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'building_id' => Building::factory(),
            'door_id' => 'DR-' . fake()->unique()->numerify('####'),
            'name' => fake()->word() . ' Door',
            'location' => fake()->address(),
            'connection_status' => 'online',
            'status' => 'online',
        ];
    }

    /**
     * Indicate the door is offline.
     */
    public function offline(): static
    {
        return $this->state(fn (array $attributes) => [
            'connection_status' => 'offline',
        ]);
    }

    /**
     * Indicate the door is unhealthy.
     */
    public function unhealthy(): static
    {
        return $this->state(fn (array $attributes) => [
            'health_status' => 'unhealthy',
        ]);
    }
}
