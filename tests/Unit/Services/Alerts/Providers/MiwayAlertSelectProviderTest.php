<?php

use App\Models\MiwayAlert;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use App\Services\Alerts\Mappers\UnifiedAlertMapper;
use App\Services\Alerts\Providers\MiwayAlertSelectProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('miway alert select provider maps unified columns', function () {
    $alert = MiwayAlert::factory()->create([
        'external_id' => 'miway:alert:12345',
        'header_text' => 'Route 17 bus detour',
        'description_text' => 'Due to road construction, Route 17 is detoured via Queen St.',
        'cause' => 'CONSTRUCTION',
        'effect' => 'DETOUR',
        'starts_at' => CarbonImmutable::parse('2026-03-31 08:00:00'),
        'ends_at' => CarbonImmutable::parse('2026-03-31 18:00:00'),
        'url' => 'https://www.miway.ca/alerts/12345',
        'detour_pdf_url' => 'https://www.miway.ca/detours/12345.pdf',
        'is_active' => true,
        'feed_updated_at' => CarbonImmutable::parse('2026-03-31 07:30:00'),
    ]);

    $row = (new MiwayAlertSelectProvider)->select(new UnifiedAlertsCriteria)->first();

    expect($row)->not->toBeNull();
    expect($row->id)->toBe('miway:miway:alert:12345');
    expect($row->source)->toBe('miway');
    expect($row->external_id)->toBe('miway:alert:12345');
    expect((int) $row->is_active)->toBe(1);
    expect((string) $row->timestamp)->toBe($alert->starts_at->format('Y-m-d H:i:s'));
    expect($row->title)->toBe('Route 17 bus detour');
    expect($row->location_name)->toBeNull();
    expect($row->lat)->toBeNull();
    expect($row->lng)->toBeNull();

    $meta = UnifiedAlertMapper::decodeMeta($row->meta);

    expect($meta['header_text'])->toBe('Route 17 bus detour');
    expect($meta['description_text'])->toBe('Due to road construction, Route 17 is detoured via Queen St.');
    expect($meta['cause'])->toBe('CONSTRUCTION');
    expect($meta['effect'])->toBe('DETOUR');
    expect($meta['url'])->toBe('https://www.miway.ca/alerts/12345');
    expect($meta['detour_pdf_url'])->toBe('https://www.miway.ca/detours/12345.pdf');
    expect($meta['ends_at'])->toBe($alert->ends_at->format('Y-m-d H:i:s'));
    expect($meta['feed_updated_at'])->toBe($alert->feed_updated_at->format('Y-m-d H:i:s'));
});

test('miway alert select provider uses starts_at with created_at fallback as timestamp', function () {
    $alert = MiwayAlert::factory()->create([
        'external_id' => 'miway:alert:99999',
        'header_text' => 'Service update',
        'starts_at' => null,
        'created_at' => CarbonImmutable::parse('2026-03-31 10:00:00'),
        'is_active' => true,
    ]);

    $row = (new MiwayAlertSelectProvider)->select(new UnifiedAlertsCriteria)->first();

    expect((string) $row->timestamp)->toBe($alert->created_at->format('Y-m-d H:i:s'));
});

test('miway alert select provider uses non-sqlite expressions when driver is not sqlite', function () {
    DB::partialMock()
        ->shouldReceive('getDriverName')
        ->andReturn('mysql');

    $sql = (new MiwayAlertSelectProvider)->select(new UnifiedAlertsCriteria)->toSql();

    expect($sql)->toContain("CONCAT('miway:', external_id)");
    expect($sql)->toContain('JSON_OBJECT(');
    expect($sql)->toContain("'header_text', header_text");
});

test('miway alert select provider uses pgsql-safe expressions when driver is pgsql', function () {
    DB::partialMock()
        ->shouldReceive('getDriverName')
        ->andReturn('pgsql');

    $sql = (new MiwayAlertSelectProvider)->select(new UnifiedAlertsCriteria)->toSql();

    expect($sql)->toContain("('miway:' || CAST(external_id AS text))");
    expect($sql)->toContain('CAST(external_id AS text) as external_id');
    expect($sql)->toContain('CAST(NULL AS double precision) as lat');
    expect($sql)->toContain('CAST(NULL AS double precision) as lng');
    expect($sql)->toContain('json_build_object(');
    expect($sql)->toContain('::jsonb');
    expect($sql)->not->toContain('JSON_OBJECT(');
});

test('miway alert select provider pushes down status and since filters', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-31 12:00:00'));

    try {
        $criteria = new UnifiedAlertsCriteria(status: 'active', since: '30m');

        $query = (new MiwayAlertSelectProvider)->select($criteria);
        $sql = strtolower($query->toSql());

        expect($sql)->toContain('is_active');
        expect($sql)->toContain('starts_at');

        expect($query->getBindings())->toContain($criteria->sinceCutoff?->toDateTimeString());
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('miway alert select provider pgsql query path includes fulltext and ilike fallback', function () {
    DB::partialMock()
        ->shouldReceive('getDriverName')
        ->andReturn('pgsql');

    $query = (new MiwayAlertSelectProvider)->select(new UnifiedAlertsCriteria(query: 'detour'));
    $sql = $query->toSql();

    expect($sql)->toContain("to_tsvector('simple', coalesce(header_text, '') || ' ' || coalesce(description_text, '')) @@ plainto_tsquery('simple', ?)");
    expect($sql)->toContain("coalesce(header_text, '') ILIKE ?");
    expect($sql)->toContain("coalesce(description_text, '') ILIKE ?");
    expect($query->getBindings())->toBe([
        'detour',
        '%detour%',
        '%detour%',
    ]);
});

test('miway alert select provider mysql query path includes fulltext and like fallback', function () {
    DB::partialMock()
        ->shouldReceive('getDriverName')
        ->andReturn('mysql');

    $query = (new MiwayAlertSelectProvider)->select(new UnifiedAlertsCriteria(query: 'construction'));
    $sql = $query->toSql();

    expect($sql)->toContain('MATCH(header_text, description_text) AGAINST');
    expect($sql)->toContain('LOWER(header_text) LIKE ?');
    expect($sql)->toContain('LOWER(description_text) LIKE ?');
    expect($query->getBindings())->toBe([
        'construction',
        '%construction%',
        '%construction%',
    ]);
});

test('miway alert select provider deactivates when status filter is cleared', function () {
    MiwayAlert::factory()->create(['is_active' => true]);
    MiwayAlert::factory()->create(['is_active' => false]);

    $criteria = new UnifiedAlertsCriteria(status: 'cleared');
    $query = (new MiwayAlertSelectProvider)->select($criteria);
    $sql = strtolower($query->toSql());

    expect($sql)->toContain('is_active');
    expect($query->count())->toBe(1);
});

test('miway alert select provider source filter returns empty when source does not match', function () {
    MiwayAlert::factory()->create();

    $criteria = new UnifiedAlertsCriteria(source: 'fire');
    $query = (new MiwayAlertSelectProvider)->select($criteria);

    expect($query->count())->toBe(0);
});
