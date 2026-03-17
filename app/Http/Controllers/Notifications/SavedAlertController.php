<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\SavedAlertStoreRequest;
use App\Http\Resources\UnifiedAlertResource;
use App\Models\SavedAlert;
use App\Services\Alerts\UnifiedAlertsQuery;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavedAlertController extends Controller
{
    public function index(Request $request, UnifiedAlertsQuery $alertsQuery): JsonResponse
    {
        $savedAlerts = SavedAlert::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->get();

        $savedIds = $savedAlerts->pluck('alert_id')->values()->all();

        if ($savedIds === []) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'saved_ids' => [],
                    'missing_alert_ids' => [],
                ],
            ]);
        }

        $hydrated = $alertsQuery->fetchByIds($savedIds);

        $hydratedById = [];
        foreach ($hydrated['items'] as $alert) {
            $hydratedById[$alert->id] = $alert;
        }

        $resources = [];
        foreach ($savedIds as $alertId) {
            if (isset($hydratedById[$alertId])) {
                $resources[] = (new UnifiedAlertResource($hydratedById[$alertId]))->resolve();
            }
        }

        return response()->json([
            'data' => $resources,
            'meta' => [
                'saved_ids' => $savedIds,
                'missing_alert_ids' => $hydrated['missing_ids'],
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
