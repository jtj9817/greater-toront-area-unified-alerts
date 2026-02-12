<?php

namespace Database\Factories;

use App\Models\TorontoAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TorontoAddress>
 */
class TorontoAddressFactory extends Factory
{
    protected $model = TorontoAddress::class;

    public function definition(): array
    {
        return [
            'street_num' => (string) fake()->numberBetween(1, 9999),
            'street_name' => fake()->streetName(),
            'lat' => (float) fake()->latitude(43.35, 44.20),
            'long' => (float) fake()->longitude(-80.05, -78.95),
            'zip' => fake()->optional()->regexify('[A-Z][0-9][A-Z] [0-9][A-Z][0-9]'),
        ];
    }
}
