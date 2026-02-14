<?php

namespace App\Http\Controllers;

use App\Http\Requests\SceneIntel\StoreSceneIntelEntryRequest;
use App\Models\FireIncident;
use App\Models\IncidentUpdate;
use App\Services\SceneIntel\SceneIntelRepository;
use Illuminate\Http\JsonResponse;

class SceneIntelController extends Controller
{
    public function __construct(
        private readonly SceneIntelRepository $repository,
    ) {}

    public function timeline(string $eventNum): JsonResponse
    {
        $this->assertIncidentExists($eventNum);

        $timeline = $this->repository->getTimeline($eventNum);

        return response()->json([
            'data' => $timeline
                ->map(fn (IncidentUpdate $update): array => $this->serializeUpdate($update))
                ->values()
                ->all(),
            'meta' => [
                'event_num' => $eventNum,
                'count' => $timeline->count(),
            ],
        ]);
    }

    public function store(StoreSceneIntelEntryRequest $request, string $eventNum): JsonResponse
    {
        $this->assertIncidentExists($eventNum);

        $validated = $request->validated();

        $entry = $this->repository->addManualEntry(
            eventNum: $eventNum,
            content: $validated['content'],
            userId: $request->user()->id,
            metadata: $validated['metadata'] ?? null,
        );

        return response()->json([
            'data' => $this->serializeUpdate($entry),
        ], 201);
    }

    private function assertIncidentExists(string $eventNum): void
    {
        FireIncident::query()
            ->where('event_num', $eventNum)
            ->firstOrFail();
    }

    /**
     * @return array{
     *     id: int,
     *     type: string,
     *     type_label: string,
     *     icon: string,
     *     content: string,
     *     timestamp: string,
     *     metadata: array<string, mixed>|null
     * }
     */
    private function serializeUpdate(IncidentUpdate $update): array
    {
        return [
            'id' => $update->id,
            'type' => $update->update_type->value,
            'type_label' => $update->update_type->label(),
            'icon' => $update->update_type->icon(),
            'content' => $update->content,
            'timestamp' => $update->created_at->toIso8601String(),
            'metadata' => $update->metadata,
        ];
    }
}
