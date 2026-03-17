<?php

namespace Database\Factories;

use App\Models\SavedAlert;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedAlert>
 */
class SavedAlertFactory extends Factory
{
    protected $model = SavedAlert::class;

    public function definition(): array
    {
        $sources = ['fire', 'police', 'transit', 'go_transit'];
        $source = fake()->randomElement($sources);
        $externalId = strtoupper(fake()->bothify('??########'));

        return [
            'user_id' => User::factory(),
            'alert_id' => "{$source}:{$externalId}",
        ];
    }
}
