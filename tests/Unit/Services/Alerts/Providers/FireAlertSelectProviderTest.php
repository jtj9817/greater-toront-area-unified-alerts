<?php

use App\Enums\IncidentUpdateType;
use App\Models\FireIncident;
use App\Models\IncidentUpdate;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use App\Services\Alerts\Mappers\UnifiedAlertMapper;
use App\Services\Alerts\Providers\FireAlertSelectProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('fire alert select provider maps unified columns', function () {
    $incident = FireIncident::factory()->create([
        'event_num' => 'F12345',
        'event_type' => 'STRUCTURE FIRE',
        'prime_street' => 'Yonge St',
        'cross_streets' => 'Dundas St',
        'dispatch_time' => CarbonImmutable::parse('2026-02-02 12:34:00'),
        'alarm_level' => 2,
        'beat' => '12A',
        'units_dispatched' => 'P1, P2',
        'is_active' => true,
    ]);

    $row = (new FireAlertSelectProvider)->select(new UnifiedAlertsCriteria)->first();

    expect($row)->not->toBeNull();
    expect($row->id)->toBe('fire:F12345');
    expect($row->source)->toBe('fire');
    expect((string) $row->external_id)->toBe('F12345');
    expect((int) $row->is_active)->toBe(1);
    expect((string) $row->timestamp)->toBe($incident->dispatch_time->format('Y-m-d H:i:s'));
    expect($row->title)->toBe('STRUCTURE FIRE');
    expect($row->location_name)->toBe('Yonge St / Dundas St');
    expect($row->lat)->toBeNull();
    expect($row->lng)->toBeNull();

    $meta = UnifiedAlertMapper::decodeMeta($row->meta);

    expect($meta['alarm_level'])->toBe(2);
    expect($meta['units_dispatched'])->toBe('P1, P2');
    expect($meta['beat'])->toBe('12A');
    expect($meta['event_num'])->toBe('F12345');
    // Verify defaults when no updates exist
    expect($meta['intel_summary'])->toBeArray();
    expect($meta['intel_summary'])->toBeEmpty();
    expect($meta['intel_last_updated'])->toBeNull();
});

test('fire alert select provider includes intel summary and last updated', function () {
    $incident = FireIncident::factory()->create(['event_num' => 'F99999']);

    IncidentUpdate::factory()->create([
        'event_num' => 'F99999',
        'update_type' => IncidentUpdateType::ALARM_CHANGE,
        'content' => 'Alarm level increased',
        'created_at' => CarbonImmutable::now()->subMinutes(10),
    ]);

    IncidentUpdate::factory()->create([
        'event_num' => 'F99999',
        'update_type' => IncidentUpdateType::RESOURCE_STATUS,
        'content' => 'Units arrived',
        'created_at' => CarbonImmutable::now()->subMinutes(5),
    ]);

    $row = (new FireAlertSelectProvider)->select(new UnifiedAlertsCriteria)->first();
    $meta = UnifiedAlertMapper::decodeMeta($row->meta);

    expect($meta['intel_last_updated'])->not->toBeNull();
    // Verify last updated is roughly now - 5 minutes
    // Actually, SQL created_at might be string, so let's check it's a string
    expect($meta['intel_last_updated'])->toBeString();
    // Verify summary timestamp format (SQLite strftime output)
    expect($meta['intel_summary'][0]['timestamp'])->toMatch('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z/');

    expect($meta['intel_summary'])->toBeArray();
    expect($meta['intel_summary'])->toHaveCount(2);

    // Verify order (descending by created_at)
    $first = $meta['intel_summary'][0];
    $second = $meta['intel_summary'][1];

    expect($first['type'])->toBe('resource_status');
    expect($first['type_label'])->toBe('Resource Update');
    expect($first['icon'])->toBe('local_fire_department');
    expect($first['content'])->toBe('Units arrived');

    expect($second['type'])->toBe('alarm_change');
});

test('fire alert select provider limits embedded intel summary to latest 3 updates', function () {
    FireIncident::factory()->create(['event_num' => 'F77777']);

    $baseTime = CarbonImmutable::parse('2026-02-14 10:00:00');

    foreach (range(1, 5) as $offset) {
        IncidentUpdate::factory()->create([
            'event_num' => 'F77777',
            'update_type' => IncidentUpdateType::MILESTONE,
            'content' => "Update {$offset}",
            'created_at' => $baseTime->addMinutes($offset),
        ]);
    }

    $row = (new FireAlertSelectProvider)->select(new UnifiedAlertsCriteria)->first();
    $meta = UnifiedAlertMapper::decodeMeta($row->meta);

    expect($meta['intel_summary'])->toBeArray();
    expect($meta['intel_summary'])->toHaveCount(3);
    expect(array_column($meta['intel_summary'], 'content'))->toBe([
        'Update 5',
        'Update 4',
        'Update 3',
    ]);
});

test('fire alert select provider uses non-sqlite expressions when driver is not sqlite', function () {
    DB::partialMock()
        ->shouldReceive('getDriverName')
        ->andReturn('mysql');

    $sql = (new FireAlertSelectProvider)->select(new UnifiedAlertsCriteria)->toSql();

    expect($sql)->toContain("CONCAT('fire:', event_num)");
    expect($sql)->toContain('CAST(event_num AS CHAR)');
    expect($sql)->toContain("NULLIF(CONCAT_WS(' / ', prime_street, cross_streets), '')");
    expect($sql)->toContain('JSON_OBJECT(');
    expect($sql)->toContain("'alarm_level', alarm_level");

    // Verify MySQL specific syntax
    expect($sql)->toContain('LATERAL');
    expect($sql)->toContain('DATE_FORMAT');
});

test('fire alert select provider uses pgsql-safe expressions when driver is pgsql', function () {
    DB::partialMock()
        ->shouldReceive('getDriverName')
        ->andReturn('pgsql');

    $sql = (new FireAlertSelectProvider)->select(new UnifiedAlertsCriteria)->toSql();

    expect($sql)->toContain("('fire:' || CAST(event_num AS text))");
    expect($sql)->toContain('CAST(event_num AS text)');
    expect($sql)->toContain("NULLIF(concat_ws(' / ', prime_street, cross_streets), '')");
    expect($sql)->toContain('CAST(NULL AS double precision) as lat');
    expect($sql)->toContain('CAST(NULL AS double precision) as lng');
    expect($sql)->toContain('json_build_object(');
    expect($sql)->toContain('::jsonb');
    expect($sql)->toContain('json_agg(');
    expect($sql)->not->toContain('JSON_OBJECT(');
    expect($sql)->not->toContain('DATE_FORMAT');
});

test('fire alert select provider pushes down status and since filters', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-02-02 12:00:00'));

    try {
        $criteria = new UnifiedAlertsCriteria(status: 'active', since: '1h');

        $query = (new FireAlertSelectProvider)->select($criteria);
        $sql = strtolower($query->toSql());

        expect($sql)->toContain('is_active');
        expect($sql)->toContain('dispatch_time');

        expect($query->getBindings())->toContain($criteria->sinceCutoff?->toDateTimeString());
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('fire alert select provider source mismatch adds hard false predicate', function () {
    FireIncident::factory()->create([
        'event_num' => 'F-EXISTS',
        'is_active' => true,
    ]);

    $query = (new FireAlertSelectProvider)->select(new UnifiedAlertsCriteria(source: 'transit'));
    $sql = $query->toSql();
    $rows = $query->get();

    expect($sql)->toContain('1 = 0');
    expect($rows)->toBeEmpty();
});

test('fire alert select provider applies cleared status filter', function () {
    FireIncident::factory()->create([
        'event_num' => 'F-ACTIVE-1',
        'is_active' => true,
    ]);
    FireIncident::factory()->inactive()->create([
        'event_num' => 'F-CLEARED-1',
        'is_active' => false,
    ]);

    $rows = (new FireAlertSelectProvider)->select(new UnifiedAlertsCriteria(status: 'cleared'))->get();

    expect($rows)->toHaveCount(1);
    expect((string) $rows[0]->external_id)->toBe('F-CLEARED-1');
    expect((int) $rows[0]->is_active)->toBe(0);
});

test('fire alert select provider mysql query path includes fulltext and like fallback', function () {
    DB::partialMock()
        ->shouldReceive('getDriverName')
        ->andReturn('mysql');

    $query = (new FireAlertSelectProvider)->select(new UnifiedAlertsCriteria(query: 'Yonge'));
    $sql = $query->toSql();

    expect($sql)->toContain('MATCH(event_type, prime_street, cross_streets) AGAINST (? IN NATURAL LANGUAGE MODE)');
    expect($sql)->toContain('LOWER(event_type) LIKE ?');
    expect($sql)->toContain('LOWER(prime_street) LIKE ?');
    expect($sql)->toContain('LOWER(cross_streets) LIKE ?');
    expect($query->getBindings())->toBe([
        'Yonge',
        '%yonge%',
        '%yonge%',
        '%yonge%',
    ]);
});

test('fire alert select provider maps unknown incident update type to fallback label and icon', function () {
    FireIncident::factory()->create(['event_num' => 'F-UNKNOWN-TYPE']);

    DB::table('incident_updates')->insert([
        'event_num' => 'F-UNKNOWN-TYPE',
        'update_type' => 'custom_type',
        'content' => 'Custom update content',
        'metadata' => json_encode(['key' => 'value'], JSON_THROW_ON_ERROR),
        'source' => 'manual',
        'created_by' => null,
        'created_at' => '2026-02-25 12:00:00',
        'updated_at' => '2026-02-25 12:00:00',
    ]);

    $row = (new FireAlertSelectProvider)->select(new UnifiedAlertsCriteria)->first();
    $meta = UnifiedAlertMapper::decodeMeta($row->meta);

    expect($meta['intel_summary'])->toHaveCount(1);
    expect($meta['intel_summary'][0]['type'])->toBe('custom_type');
    expect($meta['intel_summary'][0]['type_label'])->toBe('custom_type');
    expect($meta['intel_summary'][0]['icon'])->toBe('info');
    expect($meta['intel_summary'][0]['content'])->toBe('Custom update content');
});
