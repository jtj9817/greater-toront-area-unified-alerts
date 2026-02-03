<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Services\Alerts\DTOs\UnifiedAlert
 */
class UnifiedAlertResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $location = null;

        if ($this->location !== null) {
            $location = [
                'name' => $this->location->name,
                'lat' => $this->location->lat,
                'lng' => $this->location->lng,
            ];
        }

        return [
            'id' => $this->id,
            'source' => $this->source,
            'external_id' => $this->externalId,
            'is_active' => $this->isActive,
            'timestamp' => $this->timestamp->toIso8601String(),
            'title' => $this->title,
            'location' => $location,
            'meta' => $this->meta,
        ];
    }
}
