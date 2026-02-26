<?php

use App\Models\PoliceCall;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use App\Services\Alerts\Mappers\UnifiedAlertMapper;
use App\Services\Alerts\Providers\PoliceAlertSelectProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('police alert select provider maps unified columns', function () {
    $call = PoliceCall::factory()->create([
        'object_id' => 4242,
        'call_type_code' => 'THEFT',
        'call_type' => 'THEFT OVER',
        'division' => 'D51',
        'cross_streets' => 'Queen St - Spadina Ave',
        'latitude' => 43.6500,
        'longitude' => -79.3800,
        'occurrence_time' => CarbonImmutable::parse('2026-02-02 13:00:00'),
        'is_active' => false,
    ]);

    $row = (new PoliceAlertSelectProvider)->select(new UnifiedAlertsCriteria)->first();

    expect($row)->not->toBeNull();
    expect($row->id)->toBe('police:4242');
    expect($row->source)->toBe('police');
    expect((string) $row->external_id)->toBe('4242');
    expect((int) $row->is_active)->toBe(0);
    expect((string) $row->timestamp)->toBe($call->occurrence_time->format('Y-m-d H:i:s'));
    expect($row->title)->toBe('THEFT OVER');
    expect($row->location_name)->toBe('Queen St - Spadina Ave');
    expect((float) $row->lat)->toBe(43.65);
    expect((float) $row->lng)->toBe(-79.38);

    $meta = UnifiedAlertMapper::decodeMeta($row->meta);

    expect($meta['division'])->toBe('D51');
    expect($meta['call_type_code'])->toBe('THEFT');
    expect($meta['object_id'])->toBe(4242);
});

test('police alert select provider uses non-sqlite expressions when driver is not sqlite', function () {
    DB::partialMock()
        ->shouldReceive('getDriverName')
        ->andReturn('mysql');

    $sql = (new PoliceAlertSelectProvider)->select(new UnifiedAlertsCriteria)->toSql();

    expect($sql)->toContain("CONCAT('police:', object_id)");
    expect($sql)->toContain('CAST(object_id AS CHAR)');
    expect($sql)->toContain("JSON_OBJECT('division'");
});

test('police alert select provider uses pgsql-safe expressions when driver is pgsql', function () {
    DB::partialMock()
        ->shouldReceive('getDriverName')
        ->andReturn('pgsql');

    $sql = (new PoliceAlertSelectProvider)->select(new UnifiedAlertsCriteria)->toSql();

    expect($sql)->toContain("('police:' || CAST(object_id AS text))");
    expect($sql)->toContain('CAST(object_id AS text)');
    expect($sql)->toContain('CAST(latitude AS double precision) as lat');
    expect($sql)->toContain('CAST(longitude AS double precision) as lng');
    expect($sql)->toContain('json_build_object(');
    expect($sql)->toContain('::jsonb');
    expect($sql)->not->toContain('JSON_OBJECT(');
});

test('police alert select provider pgsql query path includes fulltext and ilike fallback', function () {
    DB::partialMock()
        ->shouldReceive('getDriverName')
        ->andReturn('pgsql');

    $query = (new PoliceAlertSelectProvider)->select(new UnifiedAlertsCriteria(query: 'Assault'));
    $sql = $query->toSql();

    expect($sql)->toContain("to_tsvector('simple', coalesce(call_type, '') || ' ' || coalesce(cross_streets, '')) @@ plainto_tsquery('simple', ?)");
    expect($sql)->toContain("coalesce(call_type, '') ILIKE ?");
    expect($sql)->toContain("coalesce(cross_streets, '') ILIKE ?");
    expect($query->getBindings())->toBe([
        'Assault',
        '%assault%',
        '%assault%',
    ]);
});

test('police alert select provider pushes down status and since filters', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-02-02 12:00:00'));

    try {
        $criteria = new UnifiedAlertsCriteria(status: 'cleared', since: '30m');

        $query = (new PoliceAlertSelectProvider)->select($criteria);
        $sql = strtolower($query->toSql());

        expect($sql)->toContain('is_active');
        expect($sql)->toContain('occurrence_time');

        expect($query->getBindings())->toContain($criteria->sinceCutoff?->toDateTimeString());
    } finally {
        CarbonImmutable::setTestNow();
    }
});
