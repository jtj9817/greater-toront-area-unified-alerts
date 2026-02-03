<?php

use App\Models\FireIncident;
use App\Models\PoliceCall;
use App\Services\Alerts\DTOs\UnifiedAlert;
use App\Services\Alerts\UnifiedAlertsQuery;
use Database\Seeders\UnifiedAlertsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
    Paginator::currentPageResolver(fn () => 1);
});

/**
 * @param  array<int, UnifiedAlert>  $items
 */
function expectAlertsOrderedByDeterministicTuple(array $items): void
{
    for ($index = 1; $index < count($items); $index++) {
        $previous = $items[$index - 1];
        $current = $items[$index];

        $previousTimestamp = $previous->timestamp->getTimestamp();
        $currentTimestamp = $current->timestamp->getTimestamp();

        if ($previousTimestamp !== $currentTimestamp) {
            expect($previousTimestamp)->toBeGreaterThanOrEqual($currentTimestamp);

            continue;
        }

        if ($previous->source !== $current->source) {
            expect(strcmp($previous->source, $current->source))->toBeLessThanOrEqual(0);

            continue;
        }

        expect(strcmp($previous->externalId, $current->externalId))->toBeGreaterThanOrEqual(0);
    }
}

test('unified alerts query returns empty results when there are no source rows', function () {
    $results = app(UnifiedAlertsQuery::class)->paginate(perPage: 50, status: 'all');

    expect($results->total())->toBe(0);
    expect($results->items())->toBeEmpty();
});

test('unified alerts query returns a mixed feed ordered by timestamp desc', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-02 12:00:00'));
    $this->seed(UnifiedAlertsTestSeeder::class);

    $results = app(UnifiedAlertsQuery::class)->paginate(perPage: 50, status: 'all');

    expect($results->total())->toBe(8);
    expect($results->items()[0])->toBeInstanceOf(UnifiedAlert::class);

    $ids = collect($results->items())->map(fn (UnifiedAlert $a) => $a->id)->all();

    expect($ids)->toBe([
        'fire:FIRE-0001',
        'police:900001',
        'fire:FIRE-0002',
        'police:900002',
        'fire:FIRE-0003',
        'police:900003',
        'fire:FIRE-0004',
        'police:900004',
    ]);

    expectAlertsOrderedByDeterministicTuple($results->items());
});

test('unified alerts query filters by status', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-02 12:00:00'));
    $this->seed(UnifiedAlertsTestSeeder::class);

    $active = app(UnifiedAlertsQuery::class)->paginate(perPage: 50, status: 'active');
    expect($active->total())->toBe(4);

    $activeIds = collect($active->items())->map(fn (UnifiedAlert $a) => $a->id)->all();
    expect($activeIds)->toBe([
        'fire:FIRE-0001',
        'police:900001',
        'fire:FIRE-0002',
        'police:900002',
    ]);

    $cleared = app(UnifiedAlertsQuery::class)->paginate(perPage: 50, status: 'cleared');
    expect($cleared->total())->toBe(4);

    $clearedIds = collect($cleared->items())->map(fn (UnifiedAlert $a) => $a->id)->all();
    expect($clearedIds)->toBe([
        'fire:FIRE-0003',
        'police:900003',
        'fire:FIRE-0004',
        'police:900004',
    ]);
});

test('unified alerts query maps dto fields for each source', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-02 12:00:00'));
    $this->seed(UnifiedAlertsTestSeeder::class);

    $results = app(UnifiedAlertsQuery::class)->paginate(perPage: 50, status: 'all');
    $items = $results->items();

    /** @var UnifiedAlert $first */
    $first = $items[0];
    expect($first->id)->toBe('fire:FIRE-0001');
    expect($first->source)->toBe('fire');
    expect($first->externalId)->toBe('FIRE-0001');
    expect($first->isActive)->toBeTrue();
    expect($first->title)->toBe('STRUCTURE FIRE');
    expect($first->location)->toBeNull();
    expect($first->meta)->toBeArray();
    expect($first->meta['event_num'] ?? null)->toBe('FIRE-0001');
    expect($first->meta)->toHaveKey('alarm_level');

    /** @var UnifiedAlert $second */
    $second = $items[1];
    expect($second->id)->toBe('police:900001');
    expect($second->source)->toBe('police');
    expect($second->externalId)->toBe('900001');
    expect($second->isActive)->toBeTrue();
    expect($second->title)->toBe('ASSAULT IN PROGRESS');
    expect($second->location)->toBeNull();
    expect($second->meta)->toBeArray();
    expect($second->meta['object_id'] ?? null)->toBe(900001);
    expect($second->meta)->toHaveKey('call_type_code');
});

test('unified alerts query creates a location dto when any location fields are present', function () {
    $fireTimestamp = Carbon::parse('2026-02-02 12:30:00');
    $policeTimestamp = Carbon::parse('2026-02-02 12:31:00');

    FireIncident::factory()->create([
        'event_num' => 'FIRE-LOC-1',
        'event_type' => 'ALARM',
        'prime_street' => 'Yonge St',
        'cross_streets' => 'Dundas St',
        'dispatch_time' => $fireTimestamp,
        'is_active' => true,
    ]);

    PoliceCall::factory()->create([
        'object_id' => 777,
        'call_type' => 'THEFT',
        'cross_streets' => 'Queen St - Spadina Ave',
        'latitude' => 43.6500,
        'longitude' => -79.3800,
        'occurrence_time' => $policeTimestamp,
        'is_active' => true,
    ]);

    $results = app(UnifiedAlertsQuery::class)->paginate(perPage: 50, status: 'all');
    $byId = collect($results->items())->keyBy(fn (UnifiedAlert $a) => $a->id);

    /** @var UnifiedAlert $fire */
    $fire = $byId->get('fire:FIRE-LOC-1');
    expect($fire)->not->toBeNull();
    expect($fire->location)->not->toBeNull();
    expect($fire->location?->name)->toBe('Yonge St / Dundas St');
    expect($fire->location?->lat)->toBeNull();
    expect($fire->location?->lng)->toBeNull();

    /** @var UnifiedAlert $police */
    $police = $byId->get('police:777');
    expect($police)->not->toBeNull();
    expect($police->location)->not->toBeNull();
    expect($police->location?->name)->toBe('Queen St - Spadina Ave');
    expect($police->location?->lat)->toBe(43.65);
    expect($police->location?->lng)->toBe(-79.38);
});

test('unified alerts query paginates deterministically', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-02 12:00:00'));
    $this->seed(UnifiedAlertsTestSeeder::class);

    Paginator::currentPageResolver(fn () => 2);

    $page2 = app(UnifiedAlertsQuery::class)->paginate(perPage: 3, status: 'all');
    expect($page2->currentPage())->toBe(2);
    expect($page2->perPage())->toBe(3);
    expect($page2->total())->toBe(8);

    $ids = collect($page2->items())->map(fn (UnifiedAlert $a) => $a->id)->all();
    expect($ids)->toBe([
        'police:900002',
        'fire:FIRE-0003',
        'police:900003',
    ]);

    expectAlertsOrderedByDeterministicTuple($page2->items());
});

test('unified alerts query uses deterministic tie-breakers for identical timestamps', function () {
    $timestamp = Carbon::parse('2026-02-02 12:00:00');

    FireIncident::factory()->create([
        'event_num' => 'FIRE-TIE-A',
        'dispatch_time' => $timestamp,
        'is_active' => true,
    ]);

    FireIncident::factory()->create([
        'event_num' => 'FIRE-TIE-B',
        'dispatch_time' => $timestamp,
        'is_active' => true,
    ]);

    PoliceCall::factory()->create([
        'object_id' => 1,
        'occurrence_time' => $timestamp,
        'is_active' => true,
    ]);

    PoliceCall::factory()->create([
        'object_id' => 2,
        'occurrence_time' => $timestamp,
        'is_active' => true,
    ]);

    $results = app(UnifiedAlertsQuery::class)->paginate(perPage: 50, status: 'all');

    $ids = collect($results->items())->map(fn (UnifiedAlert $a) => $a->id)->all();

    expect($ids)->toBe([
        'fire:FIRE-TIE-B',
        'fire:FIRE-TIE-A',
        'police:2',
        'police:1',
    ]);

    expectAlertsOrderedByDeterministicTuple($results->items());
});

test('unified alerts query stays stable when ties cross a page boundary', function () {
    $timestamp = Carbon::parse('2026-02-02 12:00:00');

    FireIncident::factory()->createMany([
        ['event_num' => 'FIRE-TIE-001', 'dispatch_time' => $timestamp, 'is_active' => true],
        ['event_num' => 'FIRE-TIE-002', 'dispatch_time' => $timestamp, 'is_active' => true],
        ['event_num' => 'FIRE-TIE-003', 'dispatch_time' => $timestamp, 'is_active' => true],
        ['event_num' => 'FIRE-TIE-004', 'dispatch_time' => $timestamp, 'is_active' => true],
    ]);

    PoliceCall::factory()->createMany([
        ['object_id' => 1, 'occurrence_time' => $timestamp, 'is_active' => true],
        ['object_id' => 2, 'occurrence_time' => $timestamp, 'is_active' => true],
        ['object_id' => 3, 'occurrence_time' => $timestamp, 'is_active' => true],
        ['object_id' => 4, 'occurrence_time' => $timestamp, 'is_active' => true],
    ]);

    Paginator::currentPageResolver(fn () => 1);
    $page1 = app(UnifiedAlertsQuery::class)->paginate(perPage: 5, status: 'all');

    Paginator::currentPageResolver(fn () => 2);
    $page2 = app(UnifiedAlertsQuery::class)->paginate(perPage: 5, status: 'all');

    $page1Ids = collect($page1->items())->map(fn (UnifiedAlert $a) => $a->id)->values()->all();
    $page2Ids = collect($page2->items())->map(fn (UnifiedAlert $a) => $a->id)->values()->all();

    expect(array_intersect($page1Ids, $page2Ids))->toBeEmpty();

    $expected = [
        'fire:FIRE-TIE-004',
        'fire:FIRE-TIE-003',
        'fire:FIRE-TIE-002',
        'fire:FIRE-TIE-001',
        'police:4',
        'police:3',
        'police:2',
        'police:1',
    ];

    expect(array_merge($page1Ids, $page2Ids))->toBe($expected);

    expectAlertsOrderedByDeterministicTuple($page1->items());
    expectAlertsOrderedByDeterministicTuple($page2->items());
});

test('unified alerts query throws for invalid status values', function () {
    expect(fn () => app(UnifiedAlertsQuery::class)->paginate(perPage: 50, status: 'invalid'))
        ->toThrow(\InvalidArgumentException::class);
});
