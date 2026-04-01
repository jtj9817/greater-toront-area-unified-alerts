<?php

namespace Database\Factories;

use App\Models\YrtAlert;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<YrtAlert>
 */
class YrtAlertFactory extends Factory
{
    protected $model = YrtAlert::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $postedAt = fake()->dateTimeBetween('-6 hours', 'now');

        return [
            'external_id' => 'yrt:'.fake()->unique()->numerify('#####'),
            'title' => fake()->sentence(),
            'posted_at' => $postedAt,
            'details_url' => fake()->url(),
            'description_excerpt' => fake()->optional()->paragraph(),
            'route_text' => fake()->optional()->regexify('[0-9]{1,3} - [A-Za-z ]{5,20}'),
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
