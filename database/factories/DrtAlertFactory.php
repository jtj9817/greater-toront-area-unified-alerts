<?php

namespace Database\Factories;

use App\Models\DrtAlert;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DrtAlert>
 */
class DrtAlertFactory extends Factory
{
    protected $model = DrtAlert::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $postedAt = fake()->dateTimeBetween('-6 hours', 'now');

        return [
            'external_id' => fake()->unique()->slug(3),
            'title' => fake()->sentence(),
            'posted_at' => $postedAt,
            'when_text' => fake()->optional()->sentence(),
            'route_text' => fake()->optional()->regexify('[0-9]{1,3}( and [0-9]{1,3})?'),
            'details_url' => fake()->url(),
            'body_text' => fake()->optional()->paragraphs(2, true),
            'list_hash' => sha1((string) fake()->unique()->uuid()),
            'details_fetched_at' => fake()->optional()->dateTimeBetween('-4 hours', 'now'),
            'is_active' => true,
            'feed_updated_at' => $postedAt,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }
}
