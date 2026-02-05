<?php

use App\Models\TransitAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('it has correct fillable attributes', function () {
    $alert = new TransitAlert;

    expect($alert->getFillable())->toBe([
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
    ]);
});

test('it casts attributes correctly', function () {
    $alert = new TransitAlert([
        'active_period_start' => '2026-02-05 11:00:00',
        'active_period_end' => '2026-02-05 12:00:00',
        'feed_updated_at' => '2026-02-05 10:59:00',
        'is_active' => 1,
    ]);

    expect($alert->active_period_start)->toBeInstanceOf(DateTimeInterface::class);
    expect($alert->active_period_end)->toBeInstanceOf(DateTimeInterface::class);
    expect($alert->feed_updated_at)->toBeInstanceOf(DateTimeInterface::class);
    expect($alert->is_active)->toBeTrue();
});

test('it can scope active alerts', function () {
    TransitAlert::factory()->create(['is_active' => true]);
    TransitAlert::factory()->create(['is_active' => true]);
    TransitAlert::factory()->create(['is_active' => false]);

    expect(TransitAlert::active()->count())->toBe(2);
});

test('transit alert factory inactive state sets is_active to false', function () {
    $alert = TransitAlert::factory()->inactive()->make();

    expect($alert->is_active)->toBeFalse();
});

test('transit alert factory subway state sets subway defaults', function () {
    $alert = TransitAlert::factory()->subway()->make();

    expect($alert->route_type)->toBe('Subway');
    expect($alert->source_feed)->toBe('live-api');
    expect($alert->external_id)->toStartWith('api:');
});

test('transit alert factory elevator state sets elevator defaults', function () {
    $alert = TransitAlert::factory()->elevator()->make();

    expect($alert->route_type)->toBe('Elevator');
    expect($alert->effect)->toBe('ACCESSIBILITY_ISSUE');
    expect($alert->source_feed)->toBe('live-api');
    expect($alert->external_id)->toStartWith('api:');
});

test('transit alert factory sxa state sets sxa defaults', function () {
    $alert = TransitAlert::factory()->sxa()->make();

    expect($alert->source_feed)->toBe('sxa');
    expect($alert->external_id)->toStartWith('sxa:');
});
