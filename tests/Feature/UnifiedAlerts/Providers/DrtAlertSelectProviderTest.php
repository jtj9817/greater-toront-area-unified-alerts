<?php

use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\DrtAlert;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use App\Services\Alerts\Providers\DrtAlertSelectProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('drt provider returns source name', function () {
    $provider = new DrtAlertSelectProvider;
    expect($provider->source())->toBe(AlertSource::Drt->value);
});

test('drt provider selects active alerts', function () {
    DrtAlert::factory()->create([
        'external_id' => 'conlin-grandview-detour',
        'is_active' => true,
    ]);
    DrtAlert::factory()->create([
        'external_id' => 'route-920-921-detour',
        'is_active' => false,
    ]);

    $provider = new DrtAlertSelectProvider;
    $query = $provider->select(new UnifiedAlertsCriteria(status: AlertStatus::Active->value));

    $results = $query->get();

    expect($results)->toHaveCount(1);
    expect($results[0]->external_id)->toBe('conlin-grandview-detour');
});

test('drt provider selects cleared alerts', function () {
    DrtAlert::factory()->create([
        'external_id' => 'conlin-grandview-detour',
        'is_active' => true,
    ]);
    DrtAlert::factory()->create([
        'external_id' => 'route-920-921-detour',
        'is_active' => false,
    ]);

    $provider = new DrtAlertSelectProvider;
    $query = $provider->select(new UnifiedAlertsCriteria(status: AlertStatus::Cleared->value));

    $results = $query->get();

    expect($results)->toHaveCount(1);
    expect($results[0]->external_id)->toBe('route-920-921-detour');
});

test('drt provider selects all alerts regardless of active status', function () {
    DrtAlert::factory()->create(['is_active' => true]);
    DrtAlert::factory()->create(['is_active' => false]);

    $provider = new DrtAlertSelectProvider;
    $query = $provider->select(new UnifiedAlertsCriteria(status: AlertStatus::All->value));

    expect($query->get())->toHaveCount(2);
});

test('drt provider filters by since date using posted_at', function () {
    $now = Carbon::now();
    DrtAlert::factory()->create([
        'posted_at' => $now,
        'is_active' => true,
    ]);
    DrtAlert::factory()->create([
        'posted_at' => $now->copy()->subHours(2),
        'is_active' => true,
    ]);

    $provider = new DrtAlertSelectProvider;
    $query = $provider->select(new UnifiedAlertsCriteria(
        status: AlertStatus::All->value,
        since: '1h'
    ));

    $results = $query->get();

    expect($results)->toHaveCount(1);
});

test('drt provider handles source filtering', function () {
    DrtAlert::factory()->create();

    $provider = new DrtAlertSelectProvider;

    // Should match
    $matchQuery = $provider->select(new UnifiedAlertsCriteria(
        status: AlertStatus::All->value,
        source: 'drt'
    ));
    expect($matchQuery->get())->toHaveCount(1);

    // Should not match
    $noMatchQuery = $provider->select(new UnifiedAlertsCriteria(
        status: AlertStatus::All->value,
        source: 'fire'
    ));
    expect($noMatchQuery->get())->toHaveCount(0);
});

test('drt provider constructs unified select columns with id prefix', function () {
    DrtAlert::factory()->create([
        'external_id' => 'test-alert-slug',
        'is_active' => true,
    ]);

    $provider = new DrtAlertSelectProvider;
    $query = $provider->select(new UnifiedAlertsCriteria(status: AlertStatus::All->value));
    $result = $query->first();

    expect($result->id)->toBe('drt:test-alert-slug');
    expect($result->source)->toBe('drt');
    expect($result->external_id)->toBe('test-alert-slug');
    expect((bool) $result->is_active)->toBeTrue();
    expect($result->lat)->toBeNull();
    expect($result->lng)->toBeNull();
});

test('drt provider maps posted_at as timestamp and route_text as location_name', function () {
    $postedAt = Carbon::parse('2026-04-03 14:30:00');

    DrtAlert::factory()->create([
        'posted_at' => $postedAt,
        'route_text' => '900 and 920',
        'title' => 'Detour on Routes 900/920',
        'is_active' => true,
    ]);

    $provider = new DrtAlertSelectProvider;
    $result = $provider->select(new UnifiedAlertsCriteria(status: AlertStatus::All->value))->first();

    expect($result->title)->toBe('Detour on Routes 900/920');
    expect($result->location_name)->toBe('900 and 920');
    expect(Carbon::parse($result->timestamp)->eq($postedAt))->toBeTrue();
});

test('drt provider constructs correct meta json expression', function () {
    $postedAt = Carbon::parse('2026-04-03 10:00:00');
    $feedUpdatedAt = Carbon::parse('2026-04-03 10:05:00');

    DrtAlert::factory()->create([
        'details_url' => 'https://www.durhamregiontransit.com/en/news/test-alert.aspx',
        'when_text' => 'Effective April 3, 2026',
        'route_text' => '900',
        'body_text' => 'Full alert body text here.',
        'posted_at' => $postedAt,
        'feed_updated_at' => $feedUpdatedAt,
        'is_active' => true,
    ]);

    $provider = new DrtAlertSelectProvider;
    $result = $provider->select(new UnifiedAlertsCriteria(status: AlertStatus::All->value))->first();

    expect($result->meta)->toBeString();
    $meta = json_decode($result->meta, true);

    expect($meta['details_url'])->toBe('https://www.durhamregiontransit.com/en/news/test-alert.aspx');
    expect($meta['when_text'])->toBe('Effective April 3, 2026');
    expect($meta['route_text'])->toBe('900');
    expect($meta['body_text'])->toBe('Full alert body text here.');
    expect($meta['feed_updated_at'])->not->toBeNull();
    expect($meta['posted_at'])->not->toBeNull();
});

test('drt provider meta handles nullable fields gracefully', function () {
    DrtAlert::factory()->create([
        'when_text' => null,
        'route_text' => null,
        'body_text' => null,
        'is_active' => true,
    ]);

    $provider = new DrtAlertSelectProvider;
    $result = $provider->select(new UnifiedAlertsCriteria(status: AlertStatus::All->value))->first();

    $meta = json_decode($result->meta, true);
    expect($meta['when_text'])->toBeNull();
    expect($meta['route_text'])->toBeNull();
    expect($meta['body_text'])->toBeNull();
});

test('drt provider generates mysql fulltext search query', function () {
    DB::shouldReceive('getDriverName')->andReturn('mysql');

    $provider = new DrtAlertSelectProvider;
    $query = $provider->select(new UnifiedAlertsCriteria(
        status: AlertStatus::All->value,
        query: 'search term'
    ));

    $sql = $query->toSql();

    expect($sql)->toContain('MATCH(title, route_text, body_text, when_text) AGAINST (? IN NATURAL LANGUAGE MODE)');
    expect($sql)->toContain('LOWER(title) LIKE ?');
    expect($sql)->toContain('LOWER(route_text) LIKE ?');
    expect($sql)->toContain('LOWER(body_text) LIKE ?');
    expect($sql)->toContain('LOWER(when_text) LIKE ?');
});

test('drt provider generates pgsql fulltext search query', function () {
    DB::shouldReceive('getDriverName')->andReturn('pgsql');

    $provider = new DrtAlertSelectProvider;
    $query = $provider->select(new UnifiedAlertsCriteria(
        status: AlertStatus::All->value,
        query: 'search term'
    ));

    $sql = $query->toSql();

    expect($sql)->toContain("plainto_tsquery('simple', ?)");
    expect($sql)->toContain("coalesce(title, '') ILIKE ?");
    expect($sql)->toContain("coalesce(route_text, '') ILIKE ?");
    expect($sql)->toContain("coalesce(body_text, '') ILIKE ?");
    expect($sql)->toContain("coalesce(when_text, '') ILIKE ?");
});
