<?php

use App\Enums\AlertSource;
use App\Models\YrtAlert;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use App\Services\Alerts\Mappers\UnifiedAlertMapper;
use App\Services\Alerts\Providers\YrtAlertSelectProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('yrt alert select provider exposes yrt source enum value', function () {
    expect((new YrtAlertSelectProvider)->source())->toBe(AlertSource::Yrt->value);
});

test('yrt alert select provider maps unified columns', function () {
    $postedAt = CarbonImmutable::parse('2026-04-01 14:20:00');
    $feedUpdatedAt = CarbonImmutable::parse('2026-04-01 14:30:00');

    $alert = YrtAlert::factory()->create([
        'external_id' => 'a1234',
        'title' => '52 - Holland Landing detour',
        'posted_at' => $postedAt,
        'details_url' => 'https://www.yrt.ca/advisory/1234',
        'description_excerpt' => 'Construction near Green Lane.',
        'route_text' => '52',
        'body_text' => 'Expect delays near Green Lane during peak hours.',
        'is_active' => true,
        'feed_updated_at' => $feedUpdatedAt,
    ]);

    $row = (new YrtAlertSelectProvider)->select(new UnifiedAlertsCriteria)->first();

    expect($row)->not->toBeNull();
    expect($row->id)->toBe('yrt:a1234');
    expect($row->source)->toBe(AlertSource::Yrt->value);
    expect($row->external_id)->toBe('a1234');
    expect((int) $row->is_active)->toBe(1);
    expect((string) $row->timestamp)->toBe($alert->posted_at->format('Y-m-d H:i:s'));
    expect($row->title)->toBe('52 - Holland Landing detour');
    expect($row->location_name)->toBe('52');
    expect($row->lat)->toBeNull();
    expect($row->lng)->toBeNull();

    $meta = UnifiedAlertMapper::decodeMeta($row->meta);

    expect($meta)->toMatchArray([
        'details_url' => 'https://www.yrt.ca/advisory/1234',
        'description_excerpt' => 'Construction near Green Lane.',
        'body_text' => 'Expect delays near Green Lane during peak hours.',
        'posted_at' => $postedAt->format('Y-m-d H:i:s'),
        'feed_updated_at' => $feedUpdatedAt->format('Y-m-d H:i:s'),
    ]);
});

test('yrt alert select provider includes null-safe metadata keys', function () {
    YrtAlert::factory()->create([
        'external_id' => 'b9999',
        'description_excerpt' => null,
        'body_text' => null,
        'feed_updated_at' => null,
    ]);

    $row = (new YrtAlertSelectProvider)->select(new UnifiedAlertsCriteria)->first();
    $meta = UnifiedAlertMapper::decodeMeta($row->meta);

    expect($meta)->toHaveKeys([
        'details_url',
        'description_excerpt',
        'body_text',
        'posted_at',
        'feed_updated_at',
    ]);
    expect((string) $row->external_id)->not->toStartWith('yrt:');
    expect($meta['description_excerpt'])->toBeNull();
    expect($meta['body_text'])->toBeNull();
    expect($meta['feed_updated_at'])->toBeNull();
});

test('yrt alert select provider uses non-sqlite expressions when driver is not sqlite', function () {
    DB::partialMock()
        ->shouldReceive('getDriverName')
        ->andReturn('mysql');

    $sql = (new YrtAlertSelectProvider)->select(new UnifiedAlertsCriteria)->toSql();

    expect($sql)->toContain("CONCAT('yrt:', external_id)");
    expect($sql)->toContain('JSON_OBJECT(');
});

test('yrt alert select provider uses pgsql-safe expressions when driver is pgsql', function () {
    DB::partialMock()
        ->shouldReceive('getDriverName')
        ->andReturn('pgsql');

    $sql = (new YrtAlertSelectProvider)->select(new UnifiedAlertsCriteria)->toSql();

    expect($sql)->toContain("('yrt:' || CAST(external_id AS text))");
    expect($sql)->toContain('CAST(external_id AS text) as external_id');
    expect($sql)->toContain('CAST(NULL AS double precision) as lat');
    expect($sql)->toContain('CAST(NULL AS double precision) as lng');
    expect($sql)->toContain('json_build_object(');
    expect($sql)->toContain('::jsonb');
    expect($sql)->not->toContain('JSON_OBJECT(');
});

test('yrt alert select provider pushes down source status and since filters', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 15:00:00'));

    try {
        $criteria = new UnifiedAlertsCriteria(status: 'active', since: '30m');

        $query = (new YrtAlertSelectProvider)->select($criteria);
        $sql = strtolower($query->toSql());

        expect($sql)->toContain('is_active');
        expect($sql)->toContain('posted_at');
        expect($query->getBindings())->toContain($criteria->sinceCutoff?->toDateTimeString());
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('yrt alert select provider source filter returns empty when source does not match', function () {
    YrtAlert::factory()->create();

    $query = (new YrtAlertSelectProvider)->select(new UnifiedAlertsCriteria(source: 'fire'));

    expect($query->count())->toBe(0);
});

test('yrt alert select provider pgsql query path includes fulltext and ilike fallback', function () {
    DB::partialMock()
        ->shouldReceive('getDriverName')
        ->andReturn('pgsql');

    $query = (new YrtAlertSelectProvider)->select(new UnifiedAlertsCriteria(query: 'construction'));
    $sql = $query->toSql();

    expect($sql)->toContain("to_tsvector('simple', coalesce(title, '') || ' ' || coalesce(description_excerpt, '') || ' ' || coalesce(body_text, '') || ' ' || coalesce(route_text, '')) @@ plainto_tsquery('simple', ?)");
    expect($sql)->toContain("coalesce(title, '') ILIKE ?");
    expect($sql)->toContain("coalesce(description_excerpt, '') ILIKE ?");
    expect($sql)->toContain("coalesce(body_text, '') ILIKE ?");
    expect($sql)->toContain("coalesce(route_text, '') ILIKE ?");
    expect($query->getBindings())->toBe([
        'construction',
        '%construction%',
        '%construction%',
        '%construction%',
        '%construction%',
    ]);
});

test('yrt alert select provider mysql query path includes fulltext and like fallback', function () {
    DB::partialMock()
        ->shouldReceive('getDriverName')
        ->andReturn('mysql');

    $query = (new YrtAlertSelectProvider)->select(new UnifiedAlertsCriteria(query: 'holland'));
    $sql = $query->toSql();

    expect($sql)->toContain('MATCH(title, description_excerpt, body_text, route_text) AGAINST');
    expect($sql)->toContain('LOWER(title) LIKE ?');
    expect($sql)->toContain('LOWER(description_excerpt) LIKE ?');
    expect($sql)->toContain('LOWER(body_text) LIKE ?');
    expect($sql)->toContain('LOWER(route_text) LIKE ?');
    expect($query->getBindings())->toBe([
        'holland',
        '%holland%',
        '%holland%',
        '%holland%',
        '%holland%',
    ]);
});
