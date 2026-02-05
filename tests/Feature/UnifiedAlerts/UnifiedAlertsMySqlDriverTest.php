<?php

use App\Models\FireIncident;
use App\Models\PoliceCall;
use App\Services\Alerts\Mappers\UnifiedAlertMapper;
use App\Services\Alerts\Providers\FireAlertSelectProvider;
use App\Services\Alerts\Providers\PoliceAlertSelectProvider;
use App\Services\Alerts\UnifiedAlertsQuery;
use Carbon\CarbonImmutable;
use Database\Seeders\UnifiedAlertsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
    Paginator::currentPageResolver(fn () => 1);
});

test('mysql fire provider returns formatted location and decodable meta', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('MySQL only.');
    }

    FireIncident::factory()->create([
        'event_num' => 'FIRE-MYSQL-1',
        'event_type' => 'ALARM',
        'prime_street' => 'Yonge St',
        'cross_streets' => 'Dundas St',
        'dispatch_time' => CarbonImmutable::parse('2026-02-02 12:34:00'),
        'alarm_level' => 3,
        'beat' => '12A',
        'units_dispatched' => 'P1',
        'is_active' => true,
    ]);

    $row = (new FireAlertSelectProvider)
        ->select()
        ->where('event_num', 'FIRE-MYSQL-1')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->id)->toBe('fire:FIRE-MYSQL-1');
    expect((string) $row->external_id)->toBe('FIRE-MYSQL-1');
    expect($row->location_name)->toBe('Yonge St / Dundas St');

    $meta = UnifiedAlertMapper::decodeMeta($row->meta);
    expect($meta['alarm_level'])->toBe(3);
    expect($meta['units_dispatched'])->toBe('P1');
    expect($meta['beat'])->toBe('12A');
    expect($meta['event_num'])->toBe('FIRE-MYSQL-1');
});

test('mysql police provider returns decodable meta and coordinates', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('MySQL only.');
    }

    PoliceCall::factory()->create([
        'object_id' => 4242,
        'call_type_code' => 'THEFT',
        'call_type' => 'THEFT OVER',
        'division' => 'D51',
        'cross_streets' => 'Queen St - Spadina Ave',
        'latitude' => 43.6500,
        'longitude' => -79.3800,
        'occurrence_time' => CarbonImmutable::parse('2026-02-02 13:00:00'),
        'is_active' => false,
    ]);

    $row = (new PoliceAlertSelectProvider)
        ->select()
        ->where('object_id', 4242)
        ->first();

    expect($row)->not->toBeNull();
    expect($row->id)->toBe('police:4242');
    expect((string) $row->external_id)->toBe('4242');
    expect($row->location_name)->toBe('Queen St - Spadina Ave');
    expect((float) $row->lat)->toBe(43.65);
    expect((float) $row->lng)->toBe(-79.38);

    $meta = UnifiedAlertMapper::decodeMeta($row->meta);
    expect($meta['division'])->toBe('D51');
    expect($meta['call_type_code'])->toBe('THEFT');
    expect($meta['object_id'])->toBe(4242);
});

test('mysql unified alerts query returns a deterministic mixed feed', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('MySQL only.');
    }

    Carbon::setTestNow(Carbon::parse('2026-02-02 12:00:00'));
    $this->seed(UnifiedAlertsTestSeeder::class);

    $results = app(UnifiedAlertsQuery::class)->paginate(perPage: 50, status: 'all');

    expect($results->total())->toBe(8);

    $ids = collect($results->items())->map(fn ($alert) => $alert->id)->all();

    expect($ids)->toBe([
        'fire:FIRE-0001',
        'police:900001',
        'fire:FIRE-0002',
        'police:900002',
        'fire:FIRE-0003',
        'police:900003',
        'fire:FIRE-0004',
        'police:900004',
    ]);
});
