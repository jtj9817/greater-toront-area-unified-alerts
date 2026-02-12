<?php

namespace Database\Factories;

use App\Models\SavedPlace;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedPlace>
 */
class SavedPlaceFactory extends Factory
{
    protected $model = SavedPlace::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->streetName(),
            'lat' => (float) fake()->latitude(43.35, 44.20),
            'long' => (float) fake()->longitude(-80.05, -78.95),
            'radius' => fake()->numberBetween(250, 5000),
            'type' => fake()->randomElement(SavedPlace::TYPES),
        ];
    }
}
