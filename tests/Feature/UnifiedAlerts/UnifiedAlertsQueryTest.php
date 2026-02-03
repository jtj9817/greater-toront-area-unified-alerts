<?php

use App\Models\FireIncident;
use App\Models\PoliceCall;
use App\Services\Alerts\DTOs\UnifiedAlert;
use App\Services\Alerts\UnifiedAlertsQuery;
use Database\Seeders\UnifiedAlertsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
    Paginator::currentPageResolver(fn () => 1);
});

test('unified alerts query returns a mixed feed ordered by timestamp desc', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-02 12:00:00'));
    $this->seed(UnifiedAlertsTestSeeder::class);

    $results = app(UnifiedAlertsQuery::class)->paginate(perPage: 50, status: 'all');

    expect($results->total())->toBe(8);
    expect($results->items()[0])->toBeInstanceOf(UnifiedAlert::class);

    $ids = collect($results->items())->map(fn (UnifiedAlert $a) => $a->id)->all();

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

test('unified alerts query filters by status', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-02 12:00:00'));
    $this->seed(UnifiedAlertsTestSeeder::class);

    $active = app(UnifiedAlertsQuery::class)->paginate(perPage: 50, status: 'active');
    expect($active->total())->toBe(4);

    $activeIds = collect($active->items())->map(fn (UnifiedAlert $a) => $a->id)->all();
    expect($activeIds)->toBe([
        'fire:FIRE-0001',
        'police:900001',
        'fire:FIRE-0002',
        'police:900002',
    ]);

    $cleared = app(UnifiedAlertsQuery::class)->paginate(perPage: 50, status: 'cleared');
    expect($cleared->total())->toBe(4);

    $clearedIds = collect($cleared->items())->map(fn (UnifiedAlert $a) => $a->id)->all();
    expect($clearedIds)->toBe([
        'fire:FIRE-0003',
        'police:900003',
        'fire:FIRE-0004',
        'police:900004',
    ]);
});

test('unified alerts query paginates deterministically', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-02 12:00:00'));
    $this->seed(UnifiedAlertsTestSeeder::class);

    Paginator::currentPageResolver(fn () => 2);

    $page2 = app(UnifiedAlertsQuery::class)->paginate(perPage: 3, status: 'all');
    expect($page2->currentPage())->toBe(2);
    expect($page2->perPage())->toBe(3);
    expect($page2->total())->toBe(8);

    $ids = collect($page2->items())->map(fn (UnifiedAlert $a) => $a->id)->all();
    expect($ids)->toBe([
        'police:900002',
        'fire:FIRE-0003',
        'police:900003',
    ]);
});

test('unified alerts query uses deterministic tie-breakers for identical timestamps', function () {
    $timestamp = Carbon::parse('2026-02-02 12:00:00');

    FireIncident::factory()->create([
        'event_num' => 'FIRE-TIE-A',
        'dispatch_time' => $timestamp,
        'is_active' => true,
    ]);

    FireIncident::factory()->create([
        'event_num' => 'FIRE-TIE-B',
        'dispatch_time' => $timestamp,
        'is_active' => true,
    ]);

    PoliceCall::factory()->create([
        'object_id' => 1,
        'occurrence_time' => $timestamp,
        'is_active' => true,
    ]);

    PoliceCall::factory()->create([
        'object_id' => 2,
        'occurrence_time' => $timestamp,
        'is_active' => true,
    ]);

    $results = app(UnifiedAlertsQuery::class)->paginate(perPage: 50, status: 'all');

    $ids = collect($results->items())->map(fn (UnifiedAlert $a) => $a->id)->all();

    expect($ids)->toBe([
        'fire:FIRE-TIE-B',
        'fire:FIRE-TIE-A',
        'police:2',
        'police:1',
    ]);
});
