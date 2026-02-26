<?php

use App\Models\TransitAlert;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use App\Services\Alerts\Mappers\UnifiedAlertMapper;
use App\Services\Alerts\Providers\TransitAlertSelectProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('transit alert select provider maps unified columns', function () {
    $alert = TransitAlert::factory()->create([
        'external_id' => 'api:61748',
        'source_feed' => 'live-api',
        'route_type' => 'Subway',
        'route' => '1',
        'title' => 'Line 1 early closure',
        'description' => 'No subway service overnight.',
        'severity' => 'Critical',
        'effect' => 'REDUCED_SERVICE',
        'alert_type' => 'Planned',
        'cause' => 'OTHER_CAUSE',
        'direction' => 'Both Ways',
        'stop_start' => 'Finch',
        'stop_end' => 'Eglinton',
        'url' => 'https://www.ttc.ca/service-alerts',
        'active_period_start' => CarbonImmutable::parse('2026-02-03 01:00:00'),
        'is_active' => true,
    ]);

    $row = (new TransitAlertSelectProvider)->select(new UnifiedAlertsCriteria)->first();

    expect($row)->not->toBeNull();
    expect($row->id)->toBe('transit:api:61748');
    expect($row->source)->toBe('transit');
    expect((string) $row->external_id)->toBe('api:61748');
    expect((int) $row->is_active)->toBe(1);
    expect((string) $row->timestamp)->toBe($alert->active_period_start->format('Y-m-d H:i:s'));
    expect($row->title)->toBe('Line 1 early closure');
    expect($row->location_name)->toBe('Route 1: Finch to Eglinton');
    expect($row->lat)->toBeNull();
    expect($row->lng)->toBeNull();

    $meta = UnifiedAlertMapper::decodeMeta($row->meta);

    expect($meta['route_type'])->toBe('Subway');
    expect($meta['route'])->toBe('1');
    expect($meta['severity'])->toBe('Critical');
    expect($meta['effect'])->toBe('REDUCED_SERVICE');
    expect($meta['source_feed'])->toBe('live-api');
    expect($meta['alert_type'])->toBe('Planned');
    expect($meta['description'])->toBe('No subway service overnight.');
    expect($meta['url'])->toBe('https://www.ttc.ca/service-alerts');
    expect($meta['direction'])->toBe('Both Ways');
    expect($meta['cause'])->toBe('OTHER_CAUSE');
});

test('transit alert select provider uses non-sqlite expressions when driver is not sqlite', function () {
    DB::partialMock()
        ->shouldReceive('getDriverName')
        ->andReturn('mysql');

    $sql = (new TransitAlertSelectProvider)->select(new UnifiedAlertsCriteria)->toSql();

    expect($sql)->toContain("CONCAT('transit:', external_id)");
    expect($sql)->toContain('COALESCE(active_period_start, created_at) as timestamp');
    expect($sql)->toContain('NULLIF(TRIM(CONCAT(');
    expect($sql)->toContain("JSON_OBJECT('route_type'");
});

test('transit alert select provider uses pgsql-safe expressions when driver is pgsql', function () {
    DB::partialMock()
        ->shouldReceive('getDriverName')
        ->andReturn('pgsql');

    $sql = (new TransitAlertSelectProvider)->select(new UnifiedAlertsCriteria)->toSql();

    expect($sql)->toContain("('transit:' || CAST(external_id AS text))");
    expect($sql)->toContain('CAST(external_id AS text) as external_id');
    expect($sql)->toContain('coalesce(CASE WHEN route IS NOT NULL THEN');
    expect($sql)->toContain('CAST(NULL AS double precision) as lat');
    expect($sql)->toContain('CAST(NULL AS double precision) as lng');
    expect($sql)->toContain('json_build_object(');
    expect($sql)->toContain('::jsonb');
    expect($sql)->not->toContain('JSON_OBJECT(');
});

test('transit alert select provider pushes down status and since filters', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-02-02 12:00:00'));

    try {
        $criteria = new UnifiedAlertsCriteria(status: 'active', since: '6h');

        $query = (new TransitAlertSelectProvider)->select($criteria);
        $sql = strtolower($query->toSql());

        expect($sql)->toContain('is_active');
        expect($sql)->toContain('active_period_start');
        expect($sql)->toContain('created_at');

        expect($query->getBindings())->toContain($criteria->sinceCutoff?->toDateTimeString());
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('transit alert select provider source mismatch adds hard false predicate', function () {
    TransitAlert::factory()->create([
        'external_id' => 'api:exists',
        'is_active' => true,
    ]);

    $query = (new TransitAlertSelectProvider)->select(new UnifiedAlertsCriteria(source: 'fire'));
    $sql = $query->toSql();
    $rows = $query->get();

    expect($sql)->toContain('1 = 0');
    expect($rows)->toBeEmpty();
});

test('transit alert select provider applies cleared status filter', function () {
    TransitAlert::factory()->create([
        'external_id' => 'api:active',
        'is_active' => true,
    ]);
    TransitAlert::factory()->inactive()->create([
        'external_id' => 'api:cleared',
        'is_active' => false,
    ]);

    $rows = (new TransitAlertSelectProvider)->select(new UnifiedAlertsCriteria(status: 'cleared'))->get();

    expect($rows)->toHaveCount(1);
    expect((string) $rows[0]->external_id)->toBe('api:cleared');
    expect((int) $rows[0]->is_active)->toBe(0);
});

test('transit alert select provider mysql query path includes fulltext and like fallback', function () {
    DB::partialMock()
        ->shouldReceive('getDriverName')
        ->andReturn('mysql');

    $query = (new TransitAlertSelectProvider)->select(new UnifiedAlertsCriteria(query: 'Finch'));
    $sql = $query->toSql();

    expect($sql)->toContain('MATCH(title, description, stop_start, stop_end, route, route_type) AGAINST (? IN NATURAL LANGUAGE MODE)');
    expect($sql)->toContain('LOWER(title) LIKE ?');
    expect($sql)->toContain('LOWER(route) LIKE ?');
    expect($sql)->toContain('LOWER(route_type) LIKE ?');
    expect($sql)->toContain('LOWER(stop_start) LIKE ?');
    expect($sql)->toContain('LOWER(stop_end) LIKE ?');
    expect($query->getBindings())->toBe([
        'Finch',
        '%finch%',
        '%finch%',
        '%finch%',
        '%finch%',
        '%finch%',
    ]);
});

test('transit alert select provider since cutoff falls back to created at when active period start is null', function () {
    $now = CarbonImmutable::parse('2026-02-25 12:00:00');
    CarbonImmutable::setTestNow($now);

    try {
        TransitAlert::factory()->create([
            'external_id' => 'api:old-created',
            'active_period_start' => null,
            'created_at' => $now->subHours(5),
            'updated_at' => $now->subHours(5),
        ]);

        TransitAlert::factory()->create([
            'external_id' => 'api:created-fallback-included',
            'active_period_start' => null,
            'created_at' => $now->subMinutes(40),
            'updated_at' => $now->subMinutes(40),
        ]);

        TransitAlert::factory()->create([
            'external_id' => 'api:active-period-included',
            'active_period_start' => $now->subMinutes(30),
            'created_at' => $now->subHours(6),
            'updated_at' => $now->subHours(6),
        ]);

        $criteria = new UnifiedAlertsCriteria(since: '1h');
        $rows = (new TransitAlertSelectProvider)->select($criteria)->get();
        $externalIds = collect($rows)->pluck('external_id')->map(static fn ($id) => (string) $id)->all();

        expect($externalIds)->toContain('api:created-fallback-included');
        expect($externalIds)->toContain('api:active-period-included');
        expect($externalIds)->not->toContain('api:old-created');
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('transit alert select provider since cutoff includes rows exactly at cutoff', function () {
    $now = CarbonImmutable::parse('2026-02-25 12:00:00');
    CarbonImmutable::setTestNow($now);

    try {
        $cutoff = $now->subHour();

        TransitAlert::factory()->create([
            'external_id' => 'api:created-at-cutoff',
            'active_period_start' => null,
            'created_at' => $cutoff,
            'updated_at' => $cutoff,
        ]);

        TransitAlert::factory()->create([
            'external_id' => 'api:active-start-at-cutoff',
            'active_period_start' => $cutoff,
            'created_at' => $now->subHours(4),
            'updated_at' => $now->subHours(4),
        ]);

        TransitAlert::factory()->create([
            'external_id' => 'api:just-before-cutoff',
            'active_period_start' => null,
            'created_at' => $cutoff->subSecond(),
            'updated_at' => $cutoff->subSecond(),
        ]);

        $rows = (new TransitAlertSelectProvider)->select(new UnifiedAlertsCriteria(since: '1h'))->get();
        $externalIds = collect($rows)->pluck('external_id')->map(static fn ($id) => (string) $id)->all();

        expect($externalIds)->toContain('api:created-at-cutoff');
        expect($externalIds)->toContain('api:active-start-at-cutoff');
        expect($externalIds)->not->toContain('api:just-before-cutoff');
    } finally {
        CarbonImmutable::setTestNow();
    }
});
