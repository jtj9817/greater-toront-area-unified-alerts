<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\SavedAlertStoreRequest;
use App\Models\SavedAlert;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavedAlertController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $savedAlerts = SavedAlert::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->get();

        $savedIds = $savedAlerts->pluck('alert_id')->values()->all();

        return response()->json([
            'data' => $savedAlerts
                ->map(fn (SavedAlert $saved): array => [
                    'id' => $saved->id,
                    'alert_id' => $saved->alert_id,
                    'saved_at' => $saved->created_at->toIso8601String(),
                ])
                ->values()
                ->all(),
            'meta' => [
                'saved_ids' => $savedIds,
                'missing_alert_ids' => [],
            ],
        ]);
    }

    public function store(SavedAlertStoreRequest $request): JsonResponse
    {
        $alertId = $request->validated('alert_id');
        $userId = $request->user()->id;

        $existing = SavedAlert::query()
            ->where('user_id', $userId)
            ->where('alert_id', $alertId)
            ->first();

        if ($existing !== null) {
            return response()->json([
                'message' => 'This alert has already been saved.',
            ], 409);
        }

        try {
            $saved = SavedAlert::query()->create([
                'user_id' => $userId,
                'alert_id' => $alertId,
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json([
                'message' => 'This alert has already been saved.',
            ], 409);
        }

        return response()->json([
            'data' => [
                'id' => $saved->id,
                'alert_id' => $saved->alert_id,
                'saved_at' => $saved->created_at->toIso8601String(),
            ],
        ], 201);
    }

    public function destroy(Request $request, string $alertId): JsonResponse
    {
        $saved = SavedAlert::query()
            ->where('user_id', $request->user()->id)
            ->where('alert_id', $alertId)
            ->firstOrFail();

        $saved->delete();

        return response()->json([
            'meta' => [
                'deleted' => true,
            ],
        ]);
    }
}
