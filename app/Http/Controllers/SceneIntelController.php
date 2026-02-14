<?php

namespace App\Http\Controllers;

use App\Http\Requests\SceneIntel\StoreSceneIntelEntryRequest;
use App\Http\Resources\IncidentUpdateResource;
use App\Models\FireIncident;
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
            'data' => IncidentUpdateResource::collection($timeline),
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
            'data' => new IncidentUpdateResource($entry),
        ], 201);
    }

    private function assertIncidentExists(string $eventNum): void
    {
        if (! FireIncident::where('event_num', $eventNum)->exists()) {
            abort(404);
        }
    }
}
