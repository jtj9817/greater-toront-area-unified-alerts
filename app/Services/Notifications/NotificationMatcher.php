<?php

namespace App\Services\Notifications;

use App\Models\NotificationPreference;
use App\Models\SavedPlace;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

class NotificationMatcher
{
    public function __construct(
        private readonly AlertContentExtractor $alertContentExtractor,
    ) {}

    /** @var array<int, Collection<int, SavedPlace>> */
    private array $savedPlacesCache = [];

    /**
     * @return LazyCollection<int, NotificationPreference>
     */
    public function matchingPreferences(NotificationAlert $alert): LazyCollection
    {
        $this->savedPlacesCache = [];
        $minimumSeverity = NotificationSeverity::rank($alert->severity);

        return NotificationPreference::query()
            ->where('push_enabled', true)
            ->whereIn('alert_type', $this->candidateAlertTypes($alert))
            ->whereIn('severity_threshold', $this->candidateSeverityThresholds($minimumSeverity))
            ->cursor()
            ->filter(fn (NotificationPreference $preference): bool => $this->matches($preference, $alert));
    }

    public function matches(NotificationPreference $preference, NotificationAlert $alert): bool
    {
        if (! $this->matchesAlertType($preference, $alert)) {
            return false;
        }

        if (! $this->matchesSeverityThreshold($preference, $alert)) {
            return false;
        }

        if (! $this->matchesSubscriptions($preference, $alert)) {
            return false;
        }

        return $this->matchesGeofence($preference, $alert);
    }

    private function matchesAlertType(NotificationPreference $preference, NotificationAlert $alert): bool
    {
        return match ($preference->alert_type) {
            'all' => true,
            'transit' => in_array($alert->source, ['transit', 'go_transit', 'ttc_accessibility'], true),
            'emergency' => in_array($alert->source, ['fire', 'police'], true),
            'accessibility' => $this->isAccessibilityAlert($alert),
            default => false,
        };
    }

    /**
     * @return array<int, string>
     */
    private function candidateAlertTypes(NotificationAlert $alert): array
    {
        $types = ['all'];

        if (in_array($alert->source, ['transit', 'go_transit', 'ttc_accessibility'], true)) {
            $types[] = 'transit';

            if ($this->isAccessibilityAlert($alert)) {
                $types[] = 'accessibility';
            }
        }

        if (in_array($alert->source, ['fire', 'police'], true)) {
            $types[] = 'emergency';
        }

        return array_values(array_unique($types));
    }

    /**
     * @return array<int, string>
     */
    private function candidateSeverityThresholds(int $minimumSeverity): array
    {
        return array_values(array_filter(
            NotificationPreference::SEVERITY_THRESHOLDS,
            static fn (string $threshold): bool => NotificationSeverity::rank($threshold) <= $minimumSeverity,
        ));
    }

    private function matchesSeverityThreshold(NotificationPreference $preference, NotificationAlert $alert): bool
    {
        $threshold = NotificationSeverity::rank($preference->severity_threshold);
        $alertSeverity = NotificationSeverity::rank($alert->severity);

        return $alertSeverity >= $threshold;
    }

    private function matchesSubscriptions(NotificationPreference $preference, NotificationAlert $alert): bool
    {
        $subscriptions = $this->normalizeSubscriptions($preference->subscriptions);

        if ($subscriptions === []) {
            return true;
        }

        if (! in_array($alert->source, ['transit', 'go_transit', 'ttc_accessibility'], true)) {
            return true;
        }

        $alertUrns = $this->alertSubscriptionUrns($alert);

        if ($alertUrns === []) {
            return false;
        }

        return array_intersect($subscriptions, $alertUrns) !== [];
    }

    private function matchesGeofence(NotificationPreference $preference, NotificationAlert $alert): bool
    {
        $savedPlaces = $this->savedPlacesForUser($preference->user_id);

        if ($savedPlaces->isEmpty()) {
            return true;
        }

        if ($alert->lat === null || $alert->lng === null) {
            return false;
        }

        foreach ($savedPlaces as $savedPlace) {
            $distanceKm = $this->distanceInKilometers(
                lat1: $alert->lat,
                lng1: $alert->lng,
                lat2: (float) $savedPlace->lat,
                lng2: (float) $savedPlace->long,
            );

            if ($distanceKm <= ((float) $savedPlace->radius / 1000)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Collection<int, SavedPlace>
     */
    private function savedPlacesForUser(int $userId): Collection
    {
        if (! array_key_exists($userId, $this->savedPlacesCache)) {
            $this->savedPlacesCache[$userId] = SavedPlace::query()
                ->where('user_id', $userId)
                ->get();
        }

        return $this->savedPlacesCache[$userId];
    }

    /**
     * @param  array<int, mixed>|null  $subscriptions
     * @return array<int, string>
     */
    private function normalizeSubscriptions(?array $subscriptions): array
    {
        if (! is_array($subscriptions)) {
            return [];
        }

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
            static fn (string $subscription): bool => $subscription !== '',
        )));
    }

    /**
     * @return array<int, string>
     */
    private function alertSubscriptionUrns(NotificationAlert $alert): array
    {
        $urns = $this->alertContentExtractor->extract($alert);

        return array_values(array_unique(array_filter(
            array_map(
                static fn (string $urn): string => strtolower(trim($urn)),
                $urns,
            ),
            static fn (string $urn): bool => $urn !== '',
        )));
    }

    private function isAccessibilityAlert(NotificationAlert $alert): bool
    {
        if ($alert->source === 'ttc_accessibility') {
            return true;
        }

        if (! in_array($alert->source, ['transit', 'go_transit'], true)) {
            return false;
        }

        $haystack = strtolower(implode(' ', array_filter([
            $alert->summary,
            (string) ($alert->metadata['effect'] ?? ''),
            (string) ($alert->metadata['route_type'] ?? ''),
            (string) ($alert->metadata['description'] ?? ''),
        ])));

        foreach (['accessibility', 'accessible', 'elevator', 'escalator', 'wheel-trans', 'wheeltrans'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function distanceInKilometers(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;

        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLng = deg2rad($lng2 - $lng1);

        $a = sin($deltaLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($deltaLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }
}
