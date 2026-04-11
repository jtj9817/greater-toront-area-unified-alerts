<?php

use App\Models\FireIncident;
use App\Models\PoliceCall;
use App\Services\Alerts\Contracts\AlertSelectProvider;
use App\Services\Alerts\DTOs\UnifiedAlert;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use App\Services\Alerts\Mappers\UnifiedAlertMapper;
use App\Services\Alerts\UnifiedAlertsQuery;
use Database\Seeders\UnifiedAlertsTestSeeder;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
    Paginator::currentPageResolver(fn () => 1);
});

/**
 * @param  array<int, UnifiedAlert>  $items
 */
function expectAlertsOrderedByDeterministicTuple(array $items, string $direction = 'desc'): void
{
    $isAscending = $direction === 'asc';

    for ($index = 1; $index < count($items); $index++) {
        $previous = $items[$index - 1];
        $current = $items[$index];

        $previousTimestamp = $previous->timestamp->getTimestamp();
        $currentTimestamp = $current->timestamp->getTimestamp();

        if ($previousTimestamp !== $currentTimestamp) {
            if ($isAscending) {
                expect($previousTimestamp)->toBeLessThanOrEqual($currentTimestamp);
            } else {
                expect($previousTimestamp)->toBeGreaterThanOrEqual($currentTimestamp);
            }

            continue;
        }

        if ($isAscending) {
            expect(strcmp($previous->id, $current->id))->toBeLessThanOrEqual(0);
        } else {
            expect(strcmp($previous->id, $current->id))->toBeGreaterThanOrEqual(0);
        }
    }
}

/**
 * @param  array<int, UnifiedAlert>  $items
 */
function expectUnifiedAlertsHaveValidIdentifiers(array $items): void
{
    $ids = [];

    foreach ($items as $item) {
        expect($item)->toBeInstanceOf(UnifiedAlert::class);
        expect($item->id)->not->toBeEmpty();
        expect($item->source)->not->toBeEmpty();
        expect($item->externalId)->not->toBeEmpty();
        expect($item->isActive)->toBeBool();
        expect($item->timestamp)->toBeInstanceOf(\Carbon\CarbonImmutable::class);
        expect($item->meta)->toBeArray();

        if ($item->location !== null) {
            if ($item->location->name !== null) {
                expect($item->location->name)->toBeString();
            }

            if ($item->location->lat !== null) {
                expect($item->location->lat)->toBeFloat();
            }

            if ($item->location->lng !== null) {
                expect($item->location->lng)->toBeFloat();
            }
        }

        $ids[] = $item->id;
    }

    expect($ids)->toHaveCount(count(array_unique($ids)));
}

function emptyUnifiedSelect(string $source): Builder
{
    return DB::query()
        ->selectRaw(
            "NULL as id,\n            ? as source,\n            NULL as external_id,\n            0 as is_active,\n            NULL as timestamp,\n            NULL as title,\n            NULL as location_name,\n            NULL as lat,\n            NULL as lng,\n            NULL as meta",
            [$source]
        )
        ->whereRaw('1 = 0');
}

function singleRowUnifiedSelect(array $overrides = []): Builder
{
    $defaults = [
        'id' => 'fire:1',
        'source' => 'fire',
        'external_id' => '1',
        'is_active' => 1,
        'timestamp' => '2026-02-02 12:00:00',
        'title' => 'TEST',
        'location_name' => null,
        'lat' => null,
        'lng' => null,
        'meta' => null,
    ];

    $data = array_merge($defaults, $overrides);

    return DB::query()->selectRaw(
        "? as id,\n        ? as source,\n        ? as external_id,\n        ? as is_active,\n        ? as timestamp,\n        ? as title,\n        ? as location_name,\n        ? as lat,\n        ? as lng,\n        ? as meta",
        [
            $data['id'],
            $data['source'],
            $data['external_id'],
            $data['is_active'],
            $data['timestamp'],
            $data['title'],
            $data['location_name'],
            $data['lat'],
            $data['lng'],
            $data['meta'],
        ],
    );
}

test('unified alerts query returns empty results when there are no source rows', function () {
    $results = app(UnifiedAlertsQuery::class)->paginate(
        new UnifiedAlertsCriteria(status: 'all', perPage: 50)
    );

    expect($results->total())->toBe(0);
    expect($results->items())->toBeEmpty();
});

test('unified alerts query returns empty results when providers list is empty', function () {
    $query = new UnifiedAlertsQuery(
        providers: [],
        mapper: new UnifiedAlertMapper,
    );

    $results = $query->paginate(
        new UnifiedAlertsCriteria(status: 'all', perPage: 50)
    );

    expect($results->total())->toBe(0);
    expect($results->items())->toBeEmpty();
});

test('unified alerts query throws when provider is invalid type', function () {
    $query = new UnifiedAlertsQuery(
        providers: ['not-a-provider'],
        mapper: new UnifiedAlertMapper,
    );

    expect(fn () => $query->paginate(new UnifiedAlertsCriteria(status: 'all', perPage: 50)))
        ->toThrow(\InvalidArgumentException::class, "Invalid provider type 'string'. Expected AlertSelectProvider.");
});

test('unified alerts query throws when provider is invalid object', function () {
    $query = new UnifiedAlertsQuery(
        providers: [new stdClass],
        mapper: new UnifiedAlertMapper,
    );

    expect(fn () => $query->paginate(new UnifiedAlertsCriteria(status: 'all', perPage: 50)))
        ->toThrow(\InvalidArgumentException::class, "Invalid provider type 'stdClass'. Expected AlertSelectProvider.");
});

test('unified alerts query returns a mixed feed ordered by timestamp desc', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-02 12:00:00'));
    $this->seed(UnifiedAlertsTestSeeder::class);

    $results = app(UnifiedAlertsQuery::class)->paginate(
        new UnifiedAlertsCriteria(status: 'all', perPage: 50)
    );

    expect($results->total())->toBe(10);
    expect($results->items()[0])->toBeInstanceOf(UnifiedAlert::class);

    $ids = collect($results->items())->map(fn (UnifiedAlert $a) => $a->id)->all();

    expect($ids)->toBe([
        'fire:FIRE-0001',
        'police:900001',
        'transit:api:TR-0001',
        'fire:FIRE-0002',
        'police:900002',
        'fire:FIRE-0003',
        'police:900003',
        'transit:sxa:TR-0002',
        'fire:FIRE-0004',
        'police:900004',
    ]);

    expectAlertsOrderedByDeterministicTuple($results->items());
    expectUnifiedAlertsHaveValidIdentifiers($results->items());
});

test('unified alerts query returns a mixed feed ordered by timestamp asc', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-02 12:00:00'));
    $this->seed(UnifiedAlertsTestSeeder::class);

    $results = app(UnifiedAlertsQuery::class)->paginate(
        new UnifiedAlertsCriteria(status: 'all', sort: 'asc', perPage: 50)
    );

    $ids = collect($results->items())->map(fn (UnifiedAlert $a) => $a->id)->all();
    $expectedDesc = [
        'fire:FIRE-0001',
        'police:900001',
        'transit:api:TR-0001',
        'fire:FIRE-0002',
        'police:900002',
        'fire:FIRE-0003',
        'police:900003',
        'transit:sxa:TR-0002',
        'fire:FIRE-0004',
        'police:900004',
    ];

    expect($ids)->toBe(array_reverse($expectedDesc));
    expectAlertsOrderedByDeterministicTuple($results->items(), 'asc');
    expectUnifiedAlertsHaveValidIdentifiers($results->items());
});

test('unified alerts query filters by status', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-02 12:00:00'));
    $this->seed(UnifiedAlertsTestSeeder::class);

    $active = app(UnifiedAlertsQuery::class)->paginate(
        new UnifiedAlertsCriteria(status: 'active', perPage: 50)
    );
    expect($active->total())->toBe(5);
    expect(collect($active->items())->every(fn (UnifiedAlert $a) => $a->isActive))->toBeTrue();

    $activeIds = collect($active->items())->map(fn (UnifiedAlert $a) => $a->id)->all();
    expect($activeIds)->toBe([
        'fire:FIRE-0001',
        'police:900001',
        'transit:api:TR-0001',
        'fire:FIRE-0002',
        'police:900002',
    ]);

    $cleared = app(UnifiedAlertsQuery::class)->paginate(
        new UnifiedAlertsCriteria(status: 'cleared', perPage: 50)
    );
    expect($cleared->total())->toBe(5);
    expect(collect($cleared->items())->every(fn (UnifiedAlert $a) => ! $a->isActive))->toBeTrue();

    $clearedIds = collect($cleared->items())->map(fn (UnifiedAlert $a) => $a->id)->all();
    expect($clearedIds)->toBe([
        'fire:FIRE-0003',
        'police:900003',
        'transit:sxa:TR-0002',
        'fire:FIRE-0004',
        'police:900004',
    ]);
});

test('unified alerts query filters by source across the full dataset', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-02 12:00:00'));
    $this->seed(UnifiedAlertsTestSeeder::class);

    $results = app(UnifiedAlertsQuery::class)->paginate(
        new UnifiedAlertsCriteria(status: 'all', source: 'fire', perPage: 50)
    );

    expect($results->total())->toBe(4);
    expect(collect($results->items())->every(fn (UnifiedAlert $a) => $a->source === 'fire'))->toBeTrue();
});

test('unified alerts query skips irrelevant providers when source is specified', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-02 12:00:00'));
    $this->seed(UnifiedAlertsTestSeeder::class);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $results = app(UnifiedAlertsQuery::class)->paginate(
        new UnifiedAlertsCriteria(status: 'all', source: 'fire', perPage: 50)
    );

    DB::disableQueryLog();

    expect($results->total())->toBe(4);

    $queries = array_map(
        static fn (array $row) => strtolower((string) ($row['query'] ?? '')),
        DB::getQueryLog(),
    );

    $unifiedQueries = array_values(
        array_filter($queries, static fn (string $sql) => str_contains($sql, 'unified_alerts'))
    );

    expect($unifiedQueries)->not->toBeEmpty();

    $combined = implode("\n", $unifiedQueries);

    expect($combined)->toContain('fire_incidents');
    expect($combined)->not->toContain('police_calls');
    expect($combined)->not->toContain('transit_alerts');
    expect($combined)->not->toContain('go_transit_alerts');
});

test('unified alerts query filters by since cutoff using test now', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-02 12:00:00'));
    $this->seed(UnifiedAlertsTestSeeder::class);

    $results = app(UnifiedAlertsQuery::class)->paginate(
        new UnifiedAlertsCriteria(status: 'all', since: '30m', perPage: 50)
    );

    $ids = collect($results->items())->map(fn (UnifiedAlert $a) => $a->id)->values()->all();

    expect($ids)->toBe([
        'fire:FIRE-0001',
        'police:900001',
        'transit:api:TR-0001',
        'fire:FIRE-0002',
    ]);
});

test('unified alerts query filters by q across title and location_name (sqlite fallback)', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-02 12:00:00'));
    $this->seed(UnifiedAlertsTestSeeder::class);

    $assault = app(UnifiedAlertsQuery::class)->paginate(
        new UnifiedAlertsCriteria(status: 'all', query: 'assault', perPage: 50)
    );

    $assaultIds = collect($assault->items())->map(fn (UnifiedAlert $a) => $a->id)->values()->all();
    expect($assaultIds)->toBe(['police:900001']);

    FireIncident::factory()->create([
        'event_num' => 'FIRE-Q-LOC-1',
        'event_type' => 'ALARM',
        'prime_street' => 'Yonge St',
        'cross_streets' => 'Dundas St',
        'dispatch_time' => Carbon::now()->subMinute(),
        'is_active' => true,
    ]);

    $yonge = app(UnifiedAlertsQuery::class)->paginate(
        new UnifiedAlertsCriteria(status: 'all', query: 'yonge', perPage: 50)
    );

    $yongeIds = collect($yonge->items())->map(fn (UnifiedAlert $a) => $a->id)->values()->all();
    expect($yongeIds)->toContain('fire:FIRE-Q-LOC-1');
});

test('unified alerts query combines status, source, since, and q filters', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-02 12:00:00'));
    $this->seed(UnifiedAlertsTestSeeder::class);

    $results = app(UnifiedAlertsQuery::class)->paginate(
        new UnifiedAlertsCriteria(
            status: 'active',
            source: 'fire',
            since: '30m',
            query: 'alarm',
            perPage: 50,
        )
    );

    $ids = collect($results->items())->map(fn (UnifiedAlert $a) => $a->id)->values()->all();

    expect($ids)->toBe(['fire:FIRE-0002']);
});

test('unified alerts query maps dto fields for each source', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-02 12:00:00'));
    $this->seed(UnifiedAlertsTestSeeder::class);

    $results = app(UnifiedAlertsQuery::class)->paginate(
        new UnifiedAlertsCriteria(status: 'all', perPage: 50)
    );
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

    /** @var UnifiedAlert $third */
    $third = $items[2];
    expect($third->id)->toBe('transit:api:TR-0001');
    expect($third->source)->toBe('transit');
    expect($third->externalId)->toBe('api:TR-0001');
    expect($third->isActive)->toBeTrue();
    expect($third->title)->toBe('Line 1 service adjustment');
    expect($third->location)->not->toBeNull();
    expect($third->location?->name)->toBe('Route 1: Finch to Eglinton');
    expect($third->location?->lat)->toBeNull();
    expect($third->location?->lng)->toBeNull();
    expect($third->meta)->toBeArray();
    expect($third->meta['route_type'] ?? null)->toBe('Subway');
    expect($third->meta['severity'] ?? null)->toBe('Critical');
    expect($third->meta['effect'] ?? null)->toBe('REDUCED_SERVICE');
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

    PoliceCall::factory()->create([
        'object_id' => 778,
        'call_type' => 'THEFT',
        'cross_streets' => null,
        'latitude' => 43.6500,
        'longitude' => -79.3800,
        'occurrence_time' => $policeTimestamp->copy()->addSecond(),
        'is_active' => true,
    ]);

    PoliceCall::factory()->create([
        'object_id' => 779,
        'call_type' => 'THEFT',
        'cross_streets' => null,
        'latitude' => 0.0,
        'longitude' => 0.0,
        'occurrence_time' => $policeTimestamp->copy()->addSeconds(2),
        'is_active' => true,
    ]);

    $results = app(UnifiedAlertsQuery::class)->paginate(
        new UnifiedAlertsCriteria(status: 'all', perPage: 50)
    );
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

    /** @var UnifiedAlert $coordsOnly */
    $coordsOnly = $byId->get('police:778');
    expect($coordsOnly)->not->toBeNull();
    expect($coordsOnly->location)->not->toBeNull();
    expect($coordsOnly->location?->name)->toBeNull();
    expect($coordsOnly->location?->lat)->toBe(43.65);
    expect($coordsOnly->location?->lng)->toBe(-79.38);

    /** @var UnifiedAlert $zeroCoords */
    $zeroCoords = $byId->get('police:779');
    expect($zeroCoords)->not->toBeNull();
    expect($zeroCoords->location)->not->toBeNull();
    expect($zeroCoords->location?->name)->toBeNull();
    expect($zeroCoords->location?->lat)->toBe(0.0);
    expect($zeroCoords->location?->lng)->toBe(0.0);
});

test('unified alerts query paginates deterministically', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-02 12:00:00'));
    $this->seed(UnifiedAlertsTestSeeder::class);

    Paginator::currentPageResolver(fn () => 2);

    $page2 = app(UnifiedAlertsQuery::class)->paginate(
        new UnifiedAlertsCriteria(status: 'all', perPage: 3)
    );
    expect($page2->currentPage())->toBe(2);
    expect($page2->perPage())->toBe(3);
    expect($page2->total())->toBe(10);

    $ids = collect($page2->items())->map(fn (UnifiedAlert $a) => $a->id)->all();
    expect($ids)->toBe([
        'fire:FIRE-0002',
        'police:900002',
        'fire:FIRE-0003',
    ]);

    expectAlertsOrderedByDeterministicTuple($page2->items());
    expectUnifiedAlertsHaveValidIdentifiers($page2->items());
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

    $results = app(UnifiedAlertsQuery::class)->paginate(
        new UnifiedAlertsCriteria(status: 'all', perPage: 50)
    );

    $ids = collect($results->items())->map(fn (UnifiedAlert $a) => $a->id)->all();

    expect($ids)->toBe([
        'police:2',
        'police:1',
        'fire:FIRE-TIE-B',
        'fire:FIRE-TIE-A',
    ]);

    expectAlertsOrderedByDeterministicTuple($results->items());
    expectUnifiedAlertsHaveValidIdentifiers($results->items());
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
    $page1 = app(UnifiedAlertsQuery::class)->paginate(
        new UnifiedAlertsCriteria(status: 'all', perPage: 5)
    );

    Paginator::currentPageResolver(fn () => 2);
    $page2 = app(UnifiedAlertsQuery::class)->paginate(
        new UnifiedAlertsCriteria(status: 'all', perPage: 5)
    );

    $page1Ids = collect($page1->items())->map(fn (UnifiedAlert $a) => $a->id)->values()->all();
    $page2Ids = collect($page2->items())->map(fn (UnifiedAlert $a) => $a->id)->values()->all();

    expect(array_intersect($page1Ids, $page2Ids))->toBeEmpty();

    $expected = [
        'police:4',
        'police:3',
        'police:2',
        'police:1',
        'fire:FIRE-TIE-004',
        'fire:FIRE-TIE-003',
        'fire:FIRE-TIE-002',
        'fire:FIRE-TIE-001',
    ];

    expect(array_merge($page1Ids, $page2Ids))->toBe($expected);

    expectAlertsOrderedByDeterministicTuple($page1->items());
    expectAlertsOrderedByDeterministicTuple($page2->items());

    expectUnifiedAlertsHaveValidIdentifiers($page1->items());
    expectUnifiedAlertsHaveValidIdentifiers($page2->items());
});

test('unified alerts query cursor pagination returns deterministic batches without duplicates', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-02 12:00:00'));
    $this->seed(UnifiedAlertsTestSeeder::class);

    $baseline = app(UnifiedAlertsQuery::class)->paginate(
        new UnifiedAlertsCriteria(status: 'all', perPage: 50)
    );
    $expected = collect($baseline->items())->map(fn (UnifiedAlert $a) => $a->id)->values()->all();

    $seen = [];
    $cursor = null;

    do {
        $batch = app(UnifiedAlertsQuery::class)->cursorPaginate(
            new UnifiedAlertsCriteria(status: 'all', perPage: 3, cursor: $cursor)
        );

        /** @var array<int, UnifiedAlert> $items */
        $items = $batch['items'];
        $ids = array_map(fn (UnifiedAlert $a) => $a->id, $items);

        expect(array_intersect($seen, $ids))->toBeEmpty();
        $seen = array_merge($seen, $ids);

        $cursor = $batch['next_cursor'];
    } while ($cursor !== null);

    expect($seen)->toBe($expected);
});

test('unified alerts query cursor pagination respects ascending sort order', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-02 12:00:00'));
    $this->seed(UnifiedAlertsTestSeeder::class);

    $baseline = app(UnifiedAlertsQuery::class)->paginate(
        new UnifiedAlertsCriteria(status: 'all', sort: 'asc', perPage: 50)
    );
    $expected = collect($baseline->items())->map(fn (UnifiedAlert $a) => $a->id)->values()->all();

    $seen = [];
    $cursor = null;

    do {
        $batch = app(UnifiedAlertsQuery::class)->cursorPaginate(
            new UnifiedAlertsCriteria(status: 'all', sort: 'asc', perPage: 3, cursor: $cursor)
        );

        /** @var array<int, UnifiedAlert> $items */
        $items = $batch['items'];
        $ids = array_map(fn (UnifiedAlert $a) => $a->id, $items);

        expect(array_intersect($seen, $ids))->toBeEmpty();
        $seen = array_merge($seen, $ids);

        expectAlertsOrderedByDeterministicTuple($items, 'asc');

        $cursor = $batch['next_cursor'];
    } while ($cursor !== null);

    expect($seen)->toBe($expected);
});

test('unified alerts query throws for invalid status values', function () {
    expect(fn () => new UnifiedAlertsCriteria(status: 'invalid'))
        ->toThrow(\InvalidArgumentException::class);
});

test('unified alerts query decodes meta to an array and never leaks json exceptions', function (mixed $meta, array $expected) {
    $query = new UnifiedAlertsQuery(
        providers: [
            new class implements AlertSelectProvider
            {
                public function source(): string
                {
                    return 'fire';
                }

                public function select(UnifiedAlertsCriteria $criteria): Builder
                {
                    return emptyUnifiedSelect($this->source());
                }
            },
            new class implements AlertSelectProvider
            {
                public function source(): string
                {
                    return 'police';
                }

                public function select(UnifiedAlertsCriteria $criteria): Builder
                {
                    return emptyUnifiedSelect($this->source());
                }
            },
            new class($meta) implements AlertSelectProvider
            {
                public function __construct(private readonly mixed $meta) {}

                public function source(): string
                {
                    return 'fire';
                }

                public function select(UnifiedAlertsCriteria $criteria): Builder
                {
                    return singleRowUnifiedSelect([
                        'id' => 'meta:1',
                        'source' => $this->source(),
                        'external_id' => '1',
                        'timestamp' => '2026-02-02 12:00:00',
                        'meta' => $this->meta,
                    ]);
                }
            },
        ],
        mapper: new UnifiedAlertMapper,
    );

    $results = $query->paginate(
        new UnifiedAlertsCriteria(status: 'all', perPage: 50)
    );
    expect($results->items())->toHaveCount(1);

    /** @var UnifiedAlert $alert */
    $alert = $results->items()[0];
    expect($alert->meta)->toBe($expected);
})->with([
    'null meta' => [null, []],
    'empty meta string' => ['', []],
    'invalid json string' => ['{', []],
    'valid json object string' => ['{"k":1}', ['k' => 1]],
    'valid json scalar string' => ['"k"', []],
]);

test('UnifiedAlertsCursor coverage is maintained by instantiating it directly', function () {
    $cursor = \App\Services\Alerts\DTOs\UnifiedAlertsCursor::fromTuple(\Carbon\CarbonImmutable::parse('2023-01-01T00:00:00Z'), 'source:id');
    expect($cursor->timestamp->toIso8601String())->toBe('2023-01-01T00:00:00+00:00');
    expect($cursor->id)->toBe('source:id');

    $encoded = $cursor->encode();
    $decoded = \App\Services\Alerts\DTOs\UnifiedAlertsCursor::decode($encoded);
    expect($decoded->id)->toBe('source:id');
});

test('unified alerts query throws when timestamp is missing', function () {
    $query = new UnifiedAlertsQuery(
        providers: [
            new class implements AlertSelectProvider
            {
                public function source(): string
                {
                    return 'fire';
                }

                public function select(UnifiedAlertsCriteria $criteria): Builder
                {
                    return emptyUnifiedSelect($this->source());
                }
            },
            new class implements AlertSelectProvider
            {
                public function source(): string
                {
                    return 'police';
                }

                public function select(UnifiedAlertsCriteria $criteria): Builder
                {
                    return emptyUnifiedSelect($this->source());
                }
            },
            new class implements AlertSelectProvider
            {
                public function source(): string
                {
                    return 'fire';
                }

                public function select(UnifiedAlertsCriteria $criteria): Builder
                {
                    return singleRowUnifiedSelect([
                        'id' => 'ts:missing',
                        'source' => $this->source(),
                        'external_id' => '1',
                        'timestamp' => null,
                    ]);
                }
            },
        ],
        mapper: new UnifiedAlertMapper,
    );

    expect(fn () => $query->paginate(new UnifiedAlertsCriteria(status: 'all', perPage: 50)))
        ->toThrow(\InvalidArgumentException::class);
});

test('unified alerts query throws when timestamp is not parseable', function () {
    $query = new UnifiedAlertsQuery(
        providers: [
            new class implements AlertSelectProvider
            {
                public function source(): string
                {
                    return 'fire';
                }

                public function select(UnifiedAlertsCriteria $criteria): Builder
                {
                    return emptyUnifiedSelect($this->source());
                }
            },
            new class implements AlertSelectProvider
            {
                public function source(): string
                {
                    return 'police';
                }

                public function select(UnifiedAlertsCriteria $criteria): Builder
                {
                    return emptyUnifiedSelect($this->source());
                }
            },
            new class implements AlertSelectProvider
            {
                public function source(): string
                {
                    return 'fire';
                }

                public function select(UnifiedAlertsCriteria $criteria): Builder
                {
                    return singleRowUnifiedSelect([
                        'id' => 'ts:bad',
                        'source' => $this->source(),
                        'external_id' => '1',
                        'timestamp' => 'not-a-timestamp',
                    ]);
                }
            },
        ],
        mapper: new UnifiedAlertMapper,
    );

    expect(fn () => $query->paginate(new UnifiedAlertsCriteria(status: 'all', perPage: 50)))
        ->toThrow(\InvalidArgumentException::class);
});

test('fetchByIds chunks large ID lists to avoid sqlite parameter limits', function () {
    $query = new UnifiedAlertsQuery(
        providers: [
            new class implements AlertSelectProvider
            {
                public function source(): string
                {
                    return 'test';
                }

                public function select(UnifiedAlertsCriteria $criteria): Builder
                {
                    return emptyUnifiedSelect($this->source());
                }
            },
        ],
        mapper: new UnifiedAlertMapper,
    );

    $alertIds = array_map(
        fn (int $i): string => "test:ID{$i}",
        range(1, 1200),
    );

    $results = $query->fetchByIds($alertIds);

    expect($results['items'])->toBe([]);
    expect($results['missing_ids'])->toHaveCount(1200);
    expect($results['missing_ids'][0])->toBe('test:ID1');
    expect($results['missing_ids'][1199])->toBe('test:ID1200');
});
