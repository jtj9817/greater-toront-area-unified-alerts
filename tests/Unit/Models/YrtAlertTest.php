<?php

use App\Models\YrtAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('yrt_alerts table has expected schema', function () {
    expect(Schema::hasTable('yrt_alerts'))->toBeTrue();

    $columns = Schema::getColumnListing('yrt_alerts');

    expect($columns)->toContain(
        'id',
        'external_id',
        'title',
        'posted_at',
        'details_url',
        'description_excerpt',
        'route_text',
        'body_text',
        'list_hash',
        'details_fetched_at',
        'is_active',
        'feed_updated_at',
        'created_at',
        'updated_at',
    );
});

test('yrt_alerts table has expected indexes', function () {
    $indexes = Schema::getIndexes('yrt_alerts');
    $indexNames = array_column($indexes, 'name');

    expect($indexNames)->toContain('yrt_alerts_external_id_unique');
    expect($indexNames)->toContain('yrt_alerts_posted_at_index');
    expect($indexNames)->toContain('yrt_alerts_feed_updated_at_index');
    expect($indexNames)->toContain('yrt_alerts_is_active_posted_at_index');
});

test('yrt alert model has expected fillable attributes', function () {
    $alert = new YrtAlert;

    expect($alert->getFillable())->toBe([
        'external_id',
        'title',
        'posted_at',
        'details_url',
        'description_excerpt',
        'route_text',
        'body_text',
        'list_hash',
        'details_fetched_at',
        'is_active',
        'feed_updated_at',
    ]);
});

test('yrt alert model casts attributes correctly', function () {
    $alert = new YrtAlert([
        'posted_at' => '2026-04-01 14:00:00',
        'details_fetched_at' => '2026-04-01 14:01:00',
        'feed_updated_at' => '2026-04-01 14:02:00',
        'is_active' => 1,
    ]);

    expect($alert->posted_at)->toBeInstanceOf(DateTimeInterface::class);
    expect($alert->details_fetched_at)->toBeInstanceOf(DateTimeInterface::class);
    expect($alert->feed_updated_at)->toBeInstanceOf(DateTimeInterface::class);
    expect($alert->is_active)->toBeTrue();
});

test('yrt alert model active scope returns only active records', function () {
    YrtAlert::factory()->create(['is_active' => true]);
    YrtAlert::factory()->create(['is_active' => true]);
    YrtAlert::factory()->create(['is_active' => false]);

    expect(YrtAlert::query()->active()->count())->toBe(2);
});
