<?php

use App\Models\MiwayAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('miway_alerts table has expected schema', function () {
    expect(Schema::hasTable('miway_alerts'))->toBeTrue();

    $columns = Schema::getColumnListing('miway_alerts');
    expect($columns)->toContain(
        'id',
        'external_id',
        'header_text',
        'description_text',
        'cause',
        'effect',
        'starts_at',
        'ends_at',
        'url',
        'detour_pdf_url',
        'is_active',
        'feed_updated_at',
        'created_at',
        'updated_at'
    );
});

test('miway_alerts table has correct indexes', function () {
    $indexes = Schema::getIndexes('miway_alerts');
    $indexNames = array_column($indexes, 'name');

    expect($indexNames)->toContain('miway_alerts_external_id_unique');
    expect($indexNames)->toContain('miway_alerts_is_active_index');
});

test('miway_alerts table has mysql fulltext index for text search columns', function () {
    $driver = Schema::getConnection()->getDriverName();

    if (! in_array($driver, ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('MySQL/MariaDB only.');
    }

    $indexes = Schema::getIndexes('miway_alerts');
    $indexNames = array_column($indexes, 'name');

    expect($indexNames)->toContain('miway_alerts_fulltext');
});

test('miway alert model has expected fillable attributes', function () {
    $alert = new MiwayAlert;

    expect($alert->getFillable())->toBe([
        'external_id',
        'header_text',
        'description_text',
        'cause',
        'effect',
        'starts_at',
        'ends_at',
        'url',
        'detour_pdf_url',
        'is_active',
        'feed_updated_at',
    ]);
});

test('miway alert model casts attributes correctly', function () {
    $alert = new MiwayAlert([
        'starts_at' => '2026-03-31 11:00:00',
        'ends_at' => '2026-03-31 12:00:00',
        'feed_updated_at' => '2026-03-31 10:59:00',
        'is_active' => 1,
    ]);

    expect($alert->starts_at)->toBeInstanceOf(DateTimeInterface::class);
    expect($alert->ends_at)->toBeInstanceOf(DateTimeInterface::class);
    expect($alert->feed_updated_at)->toBeInstanceOf(DateTimeInterface::class);
    expect($alert->is_active)->toBeTrue();
});

test('miway alert model active scope returns only active records', function () {
    MiwayAlert::factory()->create(['is_active' => true]);
    MiwayAlert::factory()->create(['is_active' => true]);
    MiwayAlert::factory()->create(['is_active' => false]);

    expect(MiwayAlert::query()->active()->count())->toBe(2);
});

test('miway alert factory inactive state sets is_active to false', function () {
    $alert = MiwayAlert::factory()->inactive()->make();

    expect($alert->is_active)->toBeFalse();
});
