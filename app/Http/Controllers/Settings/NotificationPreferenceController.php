<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\NotificationPreferenceUpdateRequest;
use App\Models\NotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $preference = $this->findOrCreatePreference(
            userId: (int) $request->user()->getAuthIdentifier(),
        );

        return response()->json([
            'data' => $this->serializePreference($preference),
        ]);
    }

    public function update(NotificationPreferenceUpdateRequest $request): JsonResponse
    {
        $preference = $this->findOrCreatePreference(
            userId: (int) $request->user()->getAuthIdentifier(),
        );

        $preference->fill($request->validated());
        $preference->save();

        return response()->json([
            'data' => $this->serializePreference($preference->fresh()),
        ]);
    }

    private function findOrCreatePreference(int $userId): NotificationPreference
    {
        return NotificationPreference::query()->firstOrCreate(
            ['user_id' => $userId],
            NotificationPreference::defaultAttributes(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePreference(NotificationPreference $preference): array
    {
        return [
            'alert_type' => $preference->alert_type,
            'severity_threshold' => $preference->severity_threshold,
            'geofences' => $preference->geofences,
            'subscribed_routes' => $preference->subscribed_routes,
            'digest_mode' => $preference->digest_mode,
            'push_enabled' => $preference->push_enabled,
        ];
    }
}
