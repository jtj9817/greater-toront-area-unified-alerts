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

        $validated = $request->validated();

        if (array_key_exists('geofences', $validated) && is_array($validated['geofences'])) {
            $this->syncLegacyGeofences(
                userId: $request->user()->id,
                geofences: $validated['geofences'],
            );
            unset($validated['geofences']);
        }

        $preference->fill($validated);
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
            'geofences' => $this->serializeLegacyGeofences($preference->user_id),
            'subscribed_routes' => $preference->subscribed_routes,
            'digest_mode' => $preference->digest_mode,
            'push_enabled' => $preference->push_enabled,
        ];
    }

    /**
     * @param  array<int, array{name?: string|null, lat: float|int|string, lng: float|int|string, radius_km: float|int|string}>  $geofences
     */
    private function syncLegacyGeofences(int $userId, array $geofences): void
    {
        SavedPlace::query()
            ->where('user_id', $userId)
            ->where('type', 'legacy_geofence')
            ->delete();

        foreach ($geofences as $geofence) {
            SavedPlace::query()->create([
                'user_id' => $userId,
                'name' => trim((string) ($geofence['name'] ?? 'Saved Zone')),
                'lat' => (float) $geofence['lat'],
                'long' => (float) $geofence['lng'],
                'radius' => max(100, (int) round((float) $geofence['radius_km'] * 1000)),
                'type' => 'legacy_geofence',
            ]);
        }
    }

    /**
     * @return array<int, array{name: string, lat: float, lng: float, radius_km: float}>
     */
    private function serializeLegacyGeofences(int $userId): array
    {
        return SavedPlace::query()
            ->where('user_id', $userId)
            ->where('type', 'legacy_geofence')
            ->orderBy('id')
            ->get()
            ->map(static fn (SavedPlace $place): array => [
                'name' => $place->name,
                'lat' => (float) $place->lat,
                'lng' => (float) $place->long,
                'radius_km' => (float) ($place->radius / 1000),
            ])
            ->values()
            ->all();
    }
}
