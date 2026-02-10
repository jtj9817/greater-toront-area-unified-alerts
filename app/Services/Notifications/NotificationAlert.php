<?php

namespace App\Services\Notifications;

use Carbon\CarbonImmutable;

readonly class NotificationAlert
{
    /**
     * @param  array<int, string>  $routes
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $alertId,
        public string $source,
        public string $severity,
        public string $summary,
        public CarbonImmutable $occurredAt,
        public ?float $lat = null,
        public ?float $lng = null,
        public array $routes = [],
        public array $metadata = [],
    ) {}

    /**
     * @return array{
     *     alert_id: string,
     *     source: string,
     *     severity: string,
     *     summary: string,
     *     occurred_at: string,
     *     lat: float|null,
     *     lng: float|null,
     *     routes: array<int, string>,
     *     metadata: array<string, mixed>
     * }
     */
    public function toPayload(): array
    {
        return [
            'alert_id' => $this->alertId,
            'source' => $this->source,
            'severity' => $this->severity,
            'summary' => $this->summary,
            'occurred_at' => $this->occurredAt->toIso8601String(),
            'lat' => $this->lat,
            'lng' => $this->lng,
            'routes' => $this->routes,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            alertId: (string) ($payload['alert_id'] ?? ''),
            source: (string) ($payload['source'] ?? ''),
            severity: (string) ($payload['severity'] ?? 'minor'),
            summary: (string) ($payload['summary'] ?? ''),
            occurredAt: CarbonImmutable::parse((string) ($payload['occurred_at'] ?? now()->toIso8601String())),
            lat: isset($payload['lat']) ? (float) $payload['lat'] : null,
            lng: isset($payload['lng']) ? (float) $payload['lng'] : null,
            routes: array_values(array_filter(
                array_map(
                    static fn (mixed $value): string => trim((string) $value),
                    is_array($payload['routes'] ?? null) ? $payload['routes'] : [],
                ),
                static fn (string $value): bool => $value !== '',
            )),
            metadata: is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
        );
    }
}
