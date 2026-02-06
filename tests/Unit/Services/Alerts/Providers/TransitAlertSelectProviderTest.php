<?php

use App\Models\TransitAlert;
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

    $row = (new TransitAlertSelectProvider)->select()->first();

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

    $sql = (new TransitAlertSelectProvider)->select()->toSql();

    expect($sql)->toContain("CONCAT('transit:', external_id)");
    expect($sql)->toContain('COALESCE(active_period_start, created_at) as timestamp');
    expect($sql)->toContain('NULLIF(TRIM(CONCAT(');
    expect($sql)->toContain("JSON_OBJECT('route_type'");
});
