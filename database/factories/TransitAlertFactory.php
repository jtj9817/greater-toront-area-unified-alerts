<?php

namespace Database\Factories;

use App\Models\TransitAlert;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransitAlert>
 */
class TransitAlertFactory extends Factory
{
    protected $model = TransitAlert::class;

    public function definition(): array
    {
        $activePeriodStart = fake()->dateTimeBetween('-6 hours', 'now');
        $isElevator = fake()->boolean(20);
        $routeType = $isElevator ? 'Elevator' : fake()->randomElement(['Subway', 'Bus', 'Streetcar']);

        return [
            'external_id' => 'api:'.fake()->unique()->numerify('#####'),
            'source_feed' => 'live-api',
            'alert_type' => fake()->randomElement(['Planned', 'SiteWide']),
            'route_type' => $routeType,
            'route' => $isElevator ? implode(',', fake()->randomElements(['4', '10', '24', '25', '85', '167'], fake()->numberBetween(1, 3))) : (string) fake()->numberBetween(1, 512),
            'title' => fake()->sentence(8),
            'description' => fake()->optional()->paragraph(),
            'severity' => fake()->randomElement(['Critical', 'Minor']),
            'effect' => fake()->randomElement(['REDUCED_SERVICE', 'DETOUR', 'SIGNIFICANT_DELAYS', 'ACCESSIBILITY_ISSUE']),
            'cause' => fake()->randomElement(['OTHER_CAUSE', 'MAINTENANCE']),
            'active_period_start' => $activePeriodStart,
            'active_period_end' => fake()->optional()->dateTimeBetween($activePeriodStart, '+2 days'),
            'direction' => fake()->optional()->randomElement(['Both Ways', 'Northbound', 'Southbound', 'Eastbound', 'Westbound']),
            'stop_start' => fake()->optional()->city(),
            'stop_end' => fake()->optional()->city(),
            'url' => fake()->optional()->url(),
            'is_active' => true,
            'feed_updated_at' => $activePeriodStart,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }

    public function subway(): static
    {
        return $this->state(fn () => [
            'route_type' => 'Subway',
            'source_feed' => 'live-api',
            'external_id' => 'api:'.fake()->unique()->numerify('#####'),
            'route' => (string) fake()->numberBetween(1, 4),
        ]);
    }

    public function elevator(): static
    {
        return $this->state(fn () => [
            'route_type' => 'Elevator',
            'effect' => 'ACCESSIBILITY_ISSUE',
            'source_feed' => 'live-api',
            'external_id' => 'api:'.fake()->unique()->numerify('#####'),
        ]);
    }

    public function sxa(): static
    {
        return $this->state(fn () => [
            'source_feed' => 'sxa',
            'external_id' => 'sxa:'.fake()->unique()->uuid(),
            'alert_type' => 'Planned',
        ]);
    }
}
