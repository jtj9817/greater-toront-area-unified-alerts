<?php

use App\Models\DrtAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('drt_alerts table has expected schema', function () {
    expect(Schema::hasTable('drt_alerts'))->toBeTrue();

    $columns = Schema::getColumnListing('drt_alerts');

    expect($columns)->toContain(
        'id',
        'external_id',
        'title',
        'posted_at',
        'when_text',
        'route_text',
        'details_url',
        'body_text',
        'list_hash',
        'details_fetched_at',
        'is_active',
        'feed_updated_at',
        'created_at',
        'updated_at',
    );
});

test('drt_alerts table has expected indexes', function () {
    $indexes = Schema::getIndexes('drt_alerts');
    $indexNames = array_column($indexes, 'name');

    expect($indexNames)->toContain('drt_alerts_external_id_unique');
    expect($indexNames)->toContain('drt_alerts_posted_at_index');
    expect($indexNames)->toContain('drt_alerts_is_active_posted_at_index');
});

test('drt alert model has expected fillable attributes', function () {
    $alert = new DrtAlert;

    expect($alert->getFillable())->toBe([
        'external_id',
        'title',
        'posted_at',
        'when_text',
        'route_text',
        'details_url',
        'body_text',
        'list_hash',
        'details_fetched_at',
        'is_active',
        'feed_updated_at',
    ]);
});

test('drt alert model casts attributes correctly', function () {
    $alert = new DrtAlert([
        'posted_at' => '2026-04-03 12:00:00',
        'details_fetched_at' => '2026-04-03 12:01:00',
        'feed_updated_at' => '2026-04-03 12:02:00',
        'is_active' => 1,
    ]);

    expect($alert->posted_at)->toBeInstanceOf(DateTimeInterface::class);
    expect($alert->details_fetched_at)->toBeInstanceOf(DateTimeInterface::class);
    expect($alert->feed_updated_at)->toBeInstanceOf(DateTimeInterface::class);
    expect($alert->is_active)->toBeTrue();
});

test('drt alert model active scope returns only active records', function () {
    DrtAlert::factory()->create(['is_active' => true]);
    DrtAlert::factory()->create(['is_active' => true]);
    DrtAlert::factory()->create(['is_active' => false]);

    expect(DrtAlert::query()->active()->count())->toBe(2);
});
