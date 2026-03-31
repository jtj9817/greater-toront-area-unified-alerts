<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MiwayAlert>
 */
class MiwayAlertFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'external_id' => 'miway:'.fake()->unique()->numberBetween(10000, 99999),
            'header_text' => fake()->sentence(),
            'description_text' => fake()->paragraph(),
            'cause' => fake()->randomElement(['UNKNOWN_CAUSE', 'WEATHER', 'TECHNICAL_PROBLEM']),
            'effect' => fake()->randomElement(['NO_SERVICE', 'REDUCED_SERVICE', 'DETOUR']),
            'starts_at' => fake()->dateTimeBetween('-1 day', 'now'),
            'ends_at' => fake()->dateTimeBetween('now', '+1 day'),
            'url' => fake()->url(),
            'detour_pdf_url' => fake()->url().'.pdf',
            'is_active' => true,
            'feed_updated_at' => now(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
