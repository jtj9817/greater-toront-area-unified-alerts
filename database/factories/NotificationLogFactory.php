<?php

namespace Database\Factories;

use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationLog>
 */
class NotificationLogFactory extends Factory
{
    protected $model = NotificationLog::class;

    public function definition(): array
    {
        $sentAt = fake()->dateTimeBetween('-1 day', 'now');
        $readAt = fake()->optional(0.4)->dateTimeBetween($sentAt, 'now');
        $dismissedAt = fake()->optional(0.3)->dateTimeBetween($sentAt, 'now');

        return [
            'user_id' => User::factory(),
            'alert_id' => fake()->optional()->bothify('alert:#####'),
            'delivery_method' => fake()->randomElement(['in_app', 'push']),
            'status' => fake()->randomElement(['sent', 'delivered', 'failed', 'read', 'dismissed']),
            'sent_at' => $sentAt,
            'read_at' => $readAt,
            'dismissed_at' => $dismissedAt,
            'metadata' => [
                'source' => fake()->randomElement(['fire', 'police', 'transit', 'go_transit']),
                'summary' => fake()->sentence(),
            ],
        ];
    }
}
