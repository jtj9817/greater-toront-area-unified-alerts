<?php

use Illuminate\Support\Facades\Schema;

test('transit_alerts table exists and has expected columns', function () {
    $this->artisan('migrate');

    expect(Schema::hasTable('transit_alerts'))->toBeTrue();

    $columns = [
        'id',
        'external_id',
        'source_feed',
        'alert_type',
        'route_type',
        'route',
        'title',
        'description',
        'severity',
        'effect',
        'cause',
        'active_period_start',
        'active_period_end',
        'direction',
        'stop_start',
        'stop_end',
        'url',
        'is_active',
        'feed_updated_at',
        'created_at',
        'updated_at',
    ];

    foreach ($columns as $column) {
        expect(Schema::hasColumn('transit_alerts', $column))
            ->toBeTrue("Column '{$column}' is missing in 'transit_alerts' table");
    }
});
