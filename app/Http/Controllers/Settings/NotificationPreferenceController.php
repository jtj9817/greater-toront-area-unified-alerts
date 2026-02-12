<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\NotificationPreferenceUpdateRequest;
use App\Models\NotificationPreference;
use App\Models\SavedPlace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $preference = $this->findOrCreatePreference(
            userId: $request->user()->id,
        );

        return response()->json([
            'data' => $this->serializePreference($preference),
        ]);
    }

    public function update(NotificationPreferenceUpdateRequest $request): JsonResponse
    {
        $preference = $this->findOrCreatePreference(
            userId: $request->user()->id,
        );

        $preference->fill($request->validated());
        $preference->save();

        if ($preference->wasChanged('geofences')) {
            $this->syncLegacyGeofences($preference);
        }

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

    /**
     * Syncs legacy geofences to the SavedPlace model using bulk insertion.
     */
    private function syncLegacyGeofences(NotificationPreference $preference): void
    {
        $geofences = $preference->geofences;

        // Clear existing saved places for this user to avoid duplicates during sync
        SavedPlace::where('user_id', $preference->user_id)->delete();

        if (empty($geofences) || ! is_array($geofences)) {
            return;
        }

        $now = now();
        $places = [];

        foreach ($geofences as $geofence) {
            $places[] = [
                'user_id' => $preference->user_id,
                'name' => $geofence['name'] ?? null,
                'lat' => $geofence['lat'],
                'lng' => $geofence['lng'],
                'radius_km' => $geofence['radius_km'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (! empty($places)) {
            SavedPlace::insert($places);
        }
    }
}
