<?php

namespace Database\Factories;

use App\Models\TorontoPointOfInterest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TorontoPointOfInterest>
 */
class TorontoPointOfInterestFactory extends Factory
{
    protected $model = TorontoPointOfInterest::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'category' => fake()->randomElement(['landmark', 'park', 'school', 'hospital']),
            'lat' => (float) fake()->latitude(43.35, 44.20),
            'long' => (float) fake()->longitude(-80.05, -78.95),
        ];
    }
}
