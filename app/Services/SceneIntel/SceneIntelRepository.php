<?php

namespace App\Services\SceneIntel;

use App\Enums\IncidentUpdateType;
use App\Models\IncidentUpdate;
use Illuminate\Database\Eloquent\Collection;

class SceneIntelRepository
{
    /**
     * @return Collection<int, IncidentUpdate>
     */
    public function getLatestForIncident(string $eventNum, int $limit = 10): Collection
    {
        return IncidentUpdate::query()
            ->forIncident($eventNum)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, IncidentUpdate>
     */
    public function getTimeline(string $eventNum): Collection
    {
        return IncidentUpdate::query()
            ->forIncident($eventNum)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @return array<int, array{
     *     id: int,
     *     type: string,
     *     type_label: string,
     *     icon: string,
     *     content: string,
     *     timestamp: string,
     *     metadata: array<string, mixed>|null
     * }>
     */
    public function getSummaryForIncident(string $eventNum, int $limit = 5): array
    {
        return IncidentUpdate::query()
            ->forIncident($eventNum)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(function (IncidentUpdate $update): array {
                return [
                    'id' => $update->id,
                    'type' => $update->update_type->value,
                    'type_label' => $update->update_type->label(),
                    'icon' => $update->update_type->icon(),
                    'content' => $update->content,
                    'timestamp' => $update->created_at->toIso8601String(),
                    'metadata' => $update->metadata,
                ];
            })
            ->all();
    }

    public function addManualEntry(
        string $eventNum,
        string $content,
        int $userId,
        ?array $metadata = null,
    ): IncidentUpdate {
        return IncidentUpdate::query()->create([
            'event_num' => $eventNum,
            'update_type' => IncidentUpdateType::MANUAL_NOTE,
            'content' => $content,
            'metadata' => $metadata,
            'source' => 'manual',
            'created_by' => $userId,
        ]);
    }
}
