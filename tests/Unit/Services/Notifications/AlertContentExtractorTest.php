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

test('it extracts all configured ttc routes from text and combined route tokens', function () {
    $extractor = app(AlertContentExtractor::class);

    $urns = $extractor->extract(new NotificationAlert(
        alertId: 'transit:test3',
        source: 'transit',
        severity: 'minor',
        summary: 'Service impacts on 510/310, 509, route 29, and Line 1',
        occurredAt: CarbonImmutable::parse('2026-02-12T11:00:00Z'),
        routes: ['510/310'],
        metadata: [
            'description' => 'Airport express 900 running with delays',
        ],
    ));

    expect($urns)->toContain('route:510');
    expect($urns)->toContain('route:310');
    expect($urns)->toContain('route:509');
    expect($urns)->toContain('route:29');
    expect($urns)->toContain('route:900');
    expect($urns)->toContain('route:1');
});

test('it extracts standard bus routes not in config', function () {
    $extractor = app(AlertContentExtractor::class);

    // Route 100 is NOT in transit_data.php
    $urns = $extractor->extract(new NotificationAlert(
        alertId: 'transit:test-bus',
        source: 'transit',
        severity: 'minor',
        summary: 'Route 100 Flemingdon Park diverting',
        occurredAt: CarbonImmutable::parse('2026-02-12T10:00:00Z'),
        routes: [], // Metadata empty
        metadata: [],
    ));

    expect($urns)->toContain('route:100');
});

test('it extracts standard bus routes like 29 even if only in text', function () {
    $extractor = app(AlertContentExtractor::class);

    // Route 29 IS in transit_data.php, so it should work already, but let's verify
    $urns = $extractor->extract(new NotificationAlert(
        alertId: 'transit:test-bus-29',
        source: 'transit',
        severity: 'minor',
        summary: 'Route 29 Dufferin diverting',
        occurredAt: CarbonImmutable::parse('2026-02-12T10:00:00Z'),
        routes: [],
        metadata: [],
    ));

    expect($urns)->toContain('route:29');
});

test('it does not extract time strings as routes', function () {
    $extractor = app(AlertContentExtractor::class);

    $urns = $extractor->extract(new NotificationAlert(
        alertId: 'transit:test-time',
        source: 'transit',
        severity: 'minor',
        summary: 'Shuttle runs 11:29 PM until 5 : 35 am',
        occurredAt: CarbonImmutable::parse('2026-02-12T10:00:00Z'),
        routes: [],
        metadata: [],
    ));

    expect($urns)->not->toContain('route:11');
    expect($urns)->not->toContain('route:29');
    expect($urns)->not->toContain('route:5');
    expect($urns)->not->toContain('route:35');
});
