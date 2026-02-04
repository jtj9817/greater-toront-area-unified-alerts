<?php

use App\Models\FireIncident;
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

    $row = (new FireAlertSelectProvider)->select()->first();

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
});

test('fire alert select provider uses non-sqlite expressions when driver is not sqlite', function () {
    DB::partialMock()
        ->shouldReceive('getDriverName')
        ->andReturn('mysql');

    $sql = (new FireAlertSelectProvider)->select()->toSql();

    expect($sql)->toContain("CONCAT('fire:', event_num)");
    expect($sql)->toContain("NULLIF(CONCAT_WS(' / ', prime_street, cross_streets), '')");
    expect($sql)->toContain("JSON_OBJECT('alarm_level'");
});
