<?php

namespace Database\Factories;

use App\Enums\IncidentUpdateType;
use App\Models\IncidentUpdate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidentUpdate>
 */
class IncidentUpdateFactory extends Factory
{
    protected $model = IncidentUpdate::class;

    public function definition(): array
    {
        return [
            'event_num' => $this->faker->numerify('E########'),
            'update_type' => $this->faker->randomElement(IncidentUpdateType::cases()),
            'content' => $this->faker->sentence(),
            'metadata' => [
                'generated' => true,
            ],
            'source' => $this->faker->randomElement(['synthetic', 'manual']),
            'created_by' => null,
        ];
    }
}
