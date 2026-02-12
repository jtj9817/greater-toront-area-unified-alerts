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

        if (array_key_exists('subscribed_routes', $validated) && ! array_key_exists('subscriptions', $validated)) {
            $validated['subscriptions'] = $validated['subscribed_routes'];
        }

        unset($validated['subscribed_routes']);

        if (array_key_exists('geofences', $validated) && is_array($validated['geofences'])) {
            $this->syncLegacyGeofences(
                userId: $request->user()->id,
                geofences: $validated['geofences'],
            );
            unset($validated['geofences']);
        }

        if (array_key_exists('subscriptions', $validated) && is_array($validated['subscriptions'])) {
            $validated['subscriptions'] = $this->normalizeSubscriptions($validated['subscriptions']);
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
            'subscriptions' => $preference->subscriptions,
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

        $now = now();
        $records = [];

        foreach ($geofences as $geofence) {
            $records[] = [
                'user_id' => $userId,
                'name' => trim((string) ($geofence['name'] ?? 'Saved Zone')),
                'lat' => (float) $geofence['lat'],
                'long' => (float) $geofence['lng'],
                'radius' => max(100, (int) round((float) $geofence['radius_km'] * 1000)),
                'type' => 'legacy_geofence',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($records !== []) {
            SavedPlace::query()->insert($records);
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

    /**
     * @param  array<int, mixed>  $subscriptions
     * @return array<int, string>
     */
    private function normalizeSubscriptions(array $subscriptions): array
    {
        $normalized = array_map(static function (mixed $subscription): string {
            $value = strtolower(trim((string) $subscription));
            if ($value === '') {
                return '';
            }

            if (! str_contains($value, ':')) {
                return 'route:'.$value;
            }

            return $value;
        }, $subscriptions);

        return array_values(array_unique(array_filter(
            $normalized,
            static fn (string $value): bool => $value !== '',
        )));
    }
}
