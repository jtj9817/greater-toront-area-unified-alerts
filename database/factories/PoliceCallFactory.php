<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PoliceCall>
 */
class PoliceCallFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $callTypes = [
            ['code' => 'BREPR', 'type' => 'BREAK & ENTER IN PROGRESS'],
            ['code' => 'ASLTPR', 'type' => 'ASSAULT IN PROGRESS'],
            ['code' => 'THEFT', 'type' => 'THEFT'],
            ['code' => 'MVC', 'type' => 'MOTOR VEHICLE COLLISION'],
            ['code' => 'DRUGS', 'type' => 'DRUG OFFENCE'],
            ['code' => 'MUNTPL', 'type' => 'MUNICIPAL BY-LAW'],
            ['code' => 'SUSP', 'type' => 'SUSPICIOUS PERSON'],
        ];

        $callType = fake()->randomElement($callTypes);

        return [
            'object_id' => fake()->unique()->numberBetween(100000, 999999),
            'call_type_code' => $callType['code'],
            'call_type' => $callType['type'],
            'division' => 'D'.fake()->numberBetween(11, 55),
            'cross_streets' => fake()->streetName().' - '.fake()->streetName(),
            'latitude' => fake()->latitude(43.58, 43.85),
            'longitude' => fake()->longitude(-79.63, -79.12),
            'occurrence_time' => fake()->dateTimeBetween('-2 hours', 'now'),
            'is_active' => true,
            'feed_updated_at' => now(),
        ];
    }

    /**
     * Indicate that the call is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
