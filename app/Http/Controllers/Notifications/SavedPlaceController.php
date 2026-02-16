<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\SavedPlaceStoreRequest;
use App\Http\Requests\Notifications\SavedPlaceUpdateRequest;
use App\Models\SavedPlace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavedPlaceController extends Controller
{
    public const MAX_SAVED_PLACES = 20;

    public function index(Request $request): JsonResponse
    {
        $places = SavedPlace::query()
            ->where('user_id', $request->user()->id)
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $places->map(
                fn (SavedPlace $place): array => $this->serializePlace($place),
            )->values(),
        ]);
    }

    public function store(SavedPlaceStoreRequest $request): JsonResponse
    {
        if ($request->user()->savedPlaces()->count() >= self::MAX_SAVED_PLACES) {
            return response()->json([
                'message' => 'You have reached the maximum limit of '.self::MAX_SAVED_PLACES.' saved places.',
            ], 403);
        }

        $place = SavedPlace::query()->create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'data' => $this->serializePlace($place),
        ], 201);
    }

    public function update(SavedPlaceUpdateRequest $request, int $savedPlace): JsonResponse
    {
        $place = $this->ownedPlace($request->user()->id, $savedPlace);
        $place->fill($request->validated());
        $place->save();

        return response()->json([
            'data' => $this->serializePlace($place->fresh()),
        ]);
    }

    public function destroy(Request $request, int $savedPlace): JsonResponse
    {
        $place = $this->ownedPlace($request->user()->id, $savedPlace);
        $place->delete();

        return response()->json([
            'meta' => [
                'deleted' => true,
            ],
        ]);
    }

    private function ownedPlace(int $userId, int $savedPlaceId): SavedPlace
    {
        return SavedPlace::query()
            ->where('user_id', $userId)
            ->whereKey($savedPlaceId)
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePlace(SavedPlace $place): array
    {
        return [
            'id' => $place->id,
            'name' => $place->name,
            'lat' => $place->lat,
            'long' => $place->long,
            'radius' => $place->radius,
            'type' => $place->type,
        ];
    }
}
