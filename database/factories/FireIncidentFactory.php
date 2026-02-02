<?php

namespace Database\Factories;

use App\Models\FireIncident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FireIncident>
 */
class FireIncidentFactory extends Factory
{
    protected $model = FireIncident::class;

    public function definition(): array
    {
        $dispatchTime = $this->faker->dateTimeBetween('-6 hours', 'now');

        return [
            'event_num' => $this->faker->unique()->numerify('E########'),
            'event_type' => $this->faker->randomElement(['FIRE', 'ALRM', 'GAS', 'RESCUE']),
            'prime_street' => $this->faker->streetName(),
            'cross_streets' => $this->faker->streetName().' & '.$this->faker->streetName(),
            'dispatch_time' => $dispatchTime,
            'alarm_level' => $this->faker->numberBetween(0, 3),
            'beat' => $this->faker->bothify('##??'),
            'units_dispatched' => implode(', ', $this->faker->words($this->faker->numberBetween(1, 4))),
            'is_active' => true,
            'feed_updated_at' => $dispatchTime,
        ];
    }

    /**
     * Indicate that the incident is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
