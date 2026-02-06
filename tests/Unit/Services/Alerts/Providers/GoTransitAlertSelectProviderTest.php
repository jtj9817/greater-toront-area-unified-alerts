<?php

use App\Models\GoTransitAlert;
use App\Services\Alerts\Mappers\UnifiedAlertMapper;
use App\Services\Alerts\Providers\GoTransitAlertSelectProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('go transit alert select provider maps unified columns', function () {
    $alert = GoTransitAlert::factory()->create([
        'external_id' => 'notif:LW:TDELAY:abc123',
        'alert_type' => 'notification',
        'service_mode' => 'GO Train',
        'corridor_or_route' => 'Lakeshore West',
        'corridor_code' => 'LW',
        'sub_category' => 'TDELAY',
        'message_subject' => 'Lakeshore West delays',
        'message_body' => 'Expect 15 min delays',
        'direction' => null,
        'trip_number' => null,
        'delay_duration' => null,
        'line_colour' => '#8B4513',
        'posted_at' => CarbonImmutable::parse('2026-02-05 19:00:00'),
        'is_active' => true,
    ]);

    $row = (new GoTransitAlertSelectProvider)->select()->first();

    expect($row)->not->toBeNull();
    expect($row->id)->toBe('go_transit:notif:LW:TDELAY:abc123');
    expect($row->source)->toBe('go_transit');
    expect($row->external_id)->toBe('notif:LW:TDELAY:abc123');
    expect((int) $row->is_active)->toBe(1);
    expect((string) $row->timestamp)->toBe($alert->posted_at->format('Y-m-d H:i:s'));
    expect($row->title)->toBe('Lakeshore West delays');
    expect($row->location_name)->toBe('Lakeshore West');
    expect($row->lat)->toBeNull();
    expect($row->lng)->toBeNull();

    $meta = UnifiedAlertMapper::decodeMeta($row->meta);

    expect($meta['alert_type'])->toBe('notification');
    expect($meta['service_mode'])->toBe('GO Train');
    expect($meta['sub_category'])->toBe('TDELAY');
    expect($meta['corridor_code'])->toBe('LW');
    expect($meta['message_body'])->toBe('Expect 15 min delays');
    expect($meta['line_colour'])->toBe('#8B4513');
});

test('go transit alert select provider maps saag columns', function () {
    GoTransitAlert::factory()->create([
        'external_id' => 'saag:LW:4521',
        'alert_type' => 'saag',
        'service_mode' => 'GO Train',
        'corridor_or_route' => 'Lakeshore West',
        'corridor_code' => 'LW',
        'sub_category' => null,
        'message_subject' => 'Lakeshore West - Union Station delayed',
        'direction' => 'EASTBOUND',
        'trip_number' => '4521',
        'delay_duration' => '00:12:00',
        'posted_at' => CarbonImmutable::parse('2026-02-05 19:25:00'),
        'is_active' => true,
    ]);

    $row = (new GoTransitAlertSelectProvider)->select()->first();

    expect($row->id)->toBe('go_transit:saag:LW:4521');

    $meta = UnifiedAlertMapper::decodeMeta($row->meta);

    expect($meta['alert_type'])->toBe('saag');
    expect($meta['direction'])->toBe('EASTBOUND');
    expect($meta['trip_number'])->toBe('4521');
    expect($meta['delay_duration'])->toBe('00:12:00');
});

test('go transit alert select provider uses non-sqlite expressions when driver is not sqlite', function () {
    DB::partialMock()
        ->shouldReceive('getDriverName')
        ->andReturn('mysql');

    $sql = (new GoTransitAlertSelectProvider)->select()->toSql();

    expect($sql)->toContain("CONCAT('go_transit:', external_id)");
    expect($sql)->toContain("JSON_OBJECT('alert_type'");
});
