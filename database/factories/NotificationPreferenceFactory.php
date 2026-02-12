<?php

namespace Database\Factories;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationPreference>
 */
class NotificationPreferenceFactory extends Factory
{
    protected $model = NotificationPreference::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'alert_type' => fake()->randomElement(NotificationPreference::ALERT_TYPES),
            'severity_threshold' => fake()->randomElement(NotificationPreference::SEVERITY_THRESHOLDS),
            'subscribed_routes' => [(string) fake()->numberBetween(1, 512)],
            'digest_mode' => fake()->boolean(25),
            'push_enabled' => fake()->boolean(90),
        ];
    }
}
