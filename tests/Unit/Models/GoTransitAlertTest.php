<?php

use App\Models\GoTransitAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('go transit alert model has expected fillable attributes', function () {
    $alert = new GoTransitAlert;

    expect($alert->getFillable())->toBe([
        'external_id',
        'alert_type',
        'service_mode',
        'corridor_or_route',
        'corridor_code',
        'sub_category',
        'message_subject',
        'message_body',
        'direction',
        'trip_number',
        'delay_duration',
        'status',
        'line_colour',
        'posted_at',
        'is_active',
        'feed_updated_at',
    ]);
});

test('go transit alert model casts posted feed updated and active attributes', function () {
    $alert = new GoTransitAlert([
        'posted_at' => '2026-02-05 11:00:00',
        'feed_updated_at' => '2026-02-05 11:05:00',
        'is_active' => 1,
    ]);

    expect($alert->posted_at)->toBeInstanceOf(DateTimeInterface::class);
    expect($alert->feed_updated_at)->toBeInstanceOf(DateTimeInterface::class);
    expect($alert->is_active)->toBeTrue();
});

test('go transit alert model active scope returns only active records', function () {
    GoTransitAlert::factory()->create(['is_active' => true]);
    GoTransitAlert::factory()->create(['is_active' => true]);
    GoTransitAlert::factory()->create(['is_active' => false]);

    expect(GoTransitAlert::query()->active()->count())->toBe(2);
});
