<?php

namespace Database\Factories;

use App\Models\GoTransitAlert;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoTransitAlert>
 */
class GoTransitAlertFactory extends Factory
{
    protected $model = GoTransitAlert::class;

    public function definition(): array
    {
        $postedAt = fake()->dateTimeBetween('-6 hours', 'now');
        $isNotification = fake()->boolean(70);

        return [
            'external_id' => $isNotification
                ? 'notif:'.fake()->unique()->bothify('??:TDELAY:????????')
                : 'saag:'.fake()->unique()->bothify('??:####'),
            'alert_type' => $isNotification ? 'notification' : 'saag',
            'service_mode' => fake()->randomElement(['GO Train', 'GO Bus', 'Station']),
            'corridor_or_route' => fake()->randomElement(['Lakeshore West', 'Lakeshore East', 'Barrie', 'Milton', 'Kitchener', 'Stouffville', 'Richmond Hill']),
            'corridor_code' => fake()->randomElement(['LW', 'LE', 'BR', 'MI', 'KI', 'ST', 'RH']),
            'sub_category' => $isNotification ? fake()->randomElement(['TDELAY', 'BCANCEL', 'BDETOUR', 'SADIS', 'GDOTHER']) : null,
            'message_subject' => fake()->sentence(6),
            'message_body' => fake()->optional()->paragraph(),
            'direction' => fake()->optional()->randomElement(['EASTBOUND', 'WESTBOUND', 'NORTHBOUND', 'SOUTHBOUND']),
            'trip_number' => $isNotification ? null : (string) fake()->numberBetween(1000, 9999),
            'delay_duration' => $isNotification ? null : '00:'.fake()->numerify('##').':00',
            'status' => fake()->randomElement(['INIT', 'UPD']),
            'line_colour' => fake()->optional()->hexColor(),
            'posted_at' => $postedAt,
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

    public function notification(): static
    {
        return $this->state(fn () => [
            'alert_type' => 'notification',
            'external_id' => 'notif:'.fake()->unique()->bothify('??:TDELAY:????????'),
            'sub_category' => fake()->randomElement(['TDELAY', 'BCANCEL', 'BDETOUR', 'SADIS', 'GDOTHER']),
            'trip_number' => null,
            'delay_duration' => null,
        ]);
    }

    public function saag(): static
    {
        return $this->state(fn () => [
            'alert_type' => 'saag',
            'external_id' => 'saag:'.fake()->unique()->bothify('??:####'),
            'sub_category' => null,
            'trip_number' => (string) fake()->numberBetween(1000, 9999),
            'delay_duration' => '00:'.fake()->numerify('##').':00',
        ]);
    }
}
