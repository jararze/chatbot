<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Truck>
 */
class TruckFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'license_plate' => strtoupper($this->faker->bothify('###???')),
            'driver_name' => $this->faker->name(),
            'model' => $this->faker->randomElement(['Scania R500', 'Volvo FH16', 'Mercedes-Benz Actros', 'DAF XF', 'MAN TGX']),
            'year' => $this->faker->numberBetween(2015, 2024),
            'last_maintenance' => $this->faker->dateTimeBetween('-6 months', 'now'),
            'status' => $this->faker->randomElement(['active', 'maintenance', 'inactive']),
            'additional_info' => $this->faker->optional(0.7)->sentence(),
            'created_at' => now(),
            'updated_at' => now()
        ];
    }

    /**
     * Indicate that the truck is active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    /**
     * Indicate that the truck is in maintenance.
     */
    public function inMaintenance(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'maintenance',
            'last_maintenance' => $this->faker->dateTimeBetween('-1 month', 'now'),
        ]);
    }
}
