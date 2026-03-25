<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Services\Weather\DTOs\WeatherData
 */
class WeatherResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'fsa' => $this->fsa,
            'provider' => $this->provider,
            'temperature' => $this->temperature,
            'humidity' => $this->humidity,
            'wind_speed' => $this->windSpeed,
            'wind_direction' => $this->windDirection,
            'condition' => $this->condition,
            'alert_level' => $this->alertLevel,
            'alert_text' => $this->alertText,
            'fetched_at' => $this->fetchedAt->format(DATE_ATOM),
        ];
    }
}
