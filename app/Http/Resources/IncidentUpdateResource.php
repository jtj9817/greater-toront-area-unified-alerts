<?php

namespace App\Http\Resources;

use App\Models\IncidentUpdate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin IncidentUpdate */
class IncidentUpdateResource extends JsonResource
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
            'type' => $this->update_type->value,
            'type_label' => $this->update_type->label(),
            'icon' => $this->update_type->icon(),
            'content' => $this->content,
            'timestamp' => $this->created_at->toIso8601String(),
            'metadata' => $this->metadata,
        ];
    }
}
