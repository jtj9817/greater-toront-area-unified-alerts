<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\FireIncident
 */
class FireIncidentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_num' => $this->event_num,
            'event_type' => $this->event_type,
            'prime_street' => $this->prime_street,
            'cross_streets' => $this->cross_streets,
            'dispatch_time' => $this->dispatch_time?->toIso8601String(),
            'alarm_level' => $this->alarm_level,
            'beat' => $this->beat,
            'units_dispatched' => $this->units_dispatched,
            'is_active' => $this->is_active,
            'feed_updated_at' => $this->feed_updated_at?->toIso8601String(),
        ];
    }
}
