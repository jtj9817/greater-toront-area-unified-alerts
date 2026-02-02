<?php

use App\Models\FireIncident;
use App\Models\PoliceCall;
use Database\Seeders\UnifiedAlertsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('fire incident factory supports inactive state', function () {
    $incident = FireIncident::factory()->inactive()->create();

    expect($incident->is_active)->toBeFalse();
});

test('unified alerts test seeder seeds mixed history data for fire and police', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-02 12:00:00'));

    try {
        $this->seed(UnifiedAlertsTestSeeder::class);
    } finally {
        Carbon::setTestNow();
    }

    expect(FireIncident::count())->toBeGreaterThan(0);
    expect(FireIncident::where('is_active', true)->count())->toBeGreaterThan(0);
    expect(FireIncident::where('is_active', false)->count())->toBeGreaterThan(0);

    expect(PoliceCall::count())->toBeGreaterThan(0);
    expect(PoliceCall::where('is_active', true)->count())->toBeGreaterThan(0);
    expect(PoliceCall::where('is_active', false)->count())->toBeGreaterThan(0);

    $oldestFire = FireIncident::orderBy('dispatch_time')->firstOrFail();
    $newestFire = FireIncident::orderByDesc('dispatch_time')->firstOrFail();

    expect($oldestFire->dispatch_time)->not->toEqual($newestFire->dispatch_time);
});
