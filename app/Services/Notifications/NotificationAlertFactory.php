<?php

namespace App\Services\Notifications;

use App\Models\FireIncident;
use App\Models\GoTransitAlert;
use App\Models\PoliceCall;
use App\Models\TransitAlert;

class NotificationAlertFactory
{
    public function fromFireIncident(FireIncident $incident): NotificationAlert
    {
        $severity = match (true) {
            $incident->alarm_level >= 2 => NotificationSeverity::CRITICAL,
            $incident->alarm_level === 1 => NotificationSeverity::MAJOR,
            default => NotificationSeverity::MINOR,
        };

        return new NotificationAlert(
            alertId: "fire:{$incident->event_num}",
            source: 'fire',
            severity: $severity,
            summary: $incident->event_type,
            occurredAt: $incident->dispatch_time,
            metadata: [
                'event_num' => $incident->event_num,
                'alarm_level' => $incident->alarm_level,
                'beat' => $incident->beat,
                'units_dispatched' => $incident->units_dispatched,
            ],
        );
    }

    public function fromPoliceCall(PoliceCall $call): NotificationAlert
    {
        $severity = $this->mapPoliceSeverity($call->call_type, $call->call_type_code);

        return new NotificationAlert(
            alertId: "police:{$call->object_id}",
            source: 'police',
            severity: $severity,
            summary: $call->call_type,
            occurredAt: $call->occurrence_time,
            lat: $call->latitude !== null ? (float) $call->latitude : null,
            lng: $call->longitude !== null ? (float) $call->longitude : null,
            metadata: [
                'object_id' => $call->object_id,
                'call_type_code' => $call->call_type_code,
                'division' => $call->division,
                'cross_streets' => $call->cross_streets,
            ],
        );
    }

    public function fromTransitAlert(TransitAlert $alert): NotificationAlert
    {
        $source = $alert->source_feed === 'ttc_accessibility'
            ? 'ttc_accessibility'
            : 'transit';

        return new NotificationAlert(
            alertId: "transit:{$alert->external_id}",
            source: $source,
            severity: $this->mapTransitSeverity($alert->severity),
            summary: $alert->title,
            occurredAt: $alert->active_period_start ?? $alert->created_at,
            routes: $this->splitRoutes($alert->route),
            metadata: [
                'route' => $alert->route,
                'route_type' => $alert->route_type,
                'type' => $alert->route_type,
                'effect' => $alert->effect,
                'description' => $alert->description,
                'source_feed' => $alert->source_feed,
                'stop_start' => $alert->stop_start,
                'stop_end' => $alert->stop_end,
            ],
        );
    }

    public function fromGoTransitAlert(GoTransitAlert $alert): NotificationAlert
    {
        $routes = $this->splitRoutes($alert->corridor_or_route);

        if ($alert->corridor_code !== null && trim($alert->corridor_code) !== '') {
            $routes[] = trim($alert->corridor_code);
        }

        return new NotificationAlert(
            alertId: "go_transit:{$alert->external_id}",
            source: 'go_transit',
            severity: $this->mapGoTransitSeverity(
                subCategory: $alert->sub_category,
                subject: $alert->message_subject,
            ),
            summary: $alert->message_subject,
            occurredAt: $alert->posted_at,
            routes: array_values(array_unique($routes)),
            metadata: [
                'alert_type' => $alert->alert_type,
                'corridor_code' => $alert->corridor_code,
                'service_mode' => $alert->service_mode,
                'sub_category' => $alert->sub_category,
            ],
        );
    }

    private function mapPoliceSeverity(?string $callType, ?string $callTypeCode): string
    {
        $haystack = strtolower(trim((string) $callType).' '.trim((string) $callTypeCode));

        if (str_contains($haystack, 'shoot')
            || str_contains($haystack, 'homicide')
            || str_contains($haystack, 'stabb')
            || str_contains($haystack, 'gun')) {
            return NotificationSeverity::CRITICAL;
        }

        if (str_contains($haystack, 'in progress')) {
            return NotificationSeverity::MAJOR;
        }

        return NotificationSeverity::MAJOR;
    }

    private function mapTransitSeverity(?string $severity): string
    {
        $value = strtolower(trim((string) $severity));

        if ($value === '') {
            return NotificationSeverity::MINOR;
        }

        if (str_contains($value, 'critical')) {
            return NotificationSeverity::CRITICAL;
        }

        if (str_contains($value, 'major') || str_contains($value, 'severe')) {
            return NotificationSeverity::MAJOR;
        }

        return NotificationSeverity::MINOR;
    }

    private function mapGoTransitSeverity(?string $subCategory, string $subject): string
    {
        $category = strtoupper(trim((string) $subCategory));
        $subjectLower = strtolower($subject);

        if (in_array($category, ['SADIS'], true)) {
            return NotificationSeverity::CRITICAL;
        }

        if (in_array($category, ['BCANCEL', 'BDETOUR'], true)
            || str_contains($subjectLower, 'cancel')
            || str_contains($subjectLower, 'suspended')) {
            return NotificationSeverity::MAJOR;
        }

        return NotificationSeverity::MINOR;
    }

    /**
     * @return array<int, string>
     */
    private function splitRoutes(?string $routes): array
    {
        if ($routes === null || trim($routes) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (string $part): string => trim($part),
                explode(',', $routes),
            ),
            static fn (string $part): bool => $part !== '',
        ));
    }
}
