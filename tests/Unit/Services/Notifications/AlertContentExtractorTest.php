<?php

use App\Services\Notifications\AlertContentExtractor;
use App\Services\Notifications\NotificationAlert;
use Carbon\CarbonImmutable;

uses(Tests\TestCase::class);

test('it extracts route station and line urns from transit content', function () {
    $extractor = app(AlertContentExtractor::class);

    $urns = $extractor->extract(new NotificationAlert(
        alertId: 'transit:test',
        source: 'transit',
        severity: 'major',
        summary: 'Route 504 detour at Union station on Line 1',
        occurredAt: CarbonImmutable::parse('2026-02-12T10:00:00Z'),
        routes: ['504'],
        metadata: [
            'description' => 'Overnight shuttle on 304 and service impacts at Union Station',
        ],
    ));

    expect($urns)->toContain('agency:ttc');
    expect($urns)->toContain('route:504');
    expect($urns)->toContain('route:304');
    expect($urns)->toContain('station:union');
    expect($urns)->toContain('line:1');
});

test('it returns deduplicated normalized urns', function () {
    $extractor = app(AlertContentExtractor::class);

    $urns = $extractor->extract(new NotificationAlert(
        alertId: 'transit:test2',
        source: 'transit',
        severity: 'minor',
        summary: 'Route 501 service change',
        occurredAt: CarbonImmutable::parse('2026-02-12T10:00:00Z'),
        routes: [' 501 ', '501'],
        metadata: [
            'description' => 'Route 501 short turn',
        ],
    ));

    $routeMatches = array_values(array_filter(
        $urns,
        static fn (string $urn): bool => $urn === 'route:501',
    ));

    expect($routeMatches)->toHaveCount(1);
});
