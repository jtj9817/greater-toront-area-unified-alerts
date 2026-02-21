<?php

use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\GoTransitAlert;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use App\Services\Alerts\Providers\GoTransitAlertSelectProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('go transit provider returns source name', function () {
    $provider = new GoTransitAlertSelectProvider;
    expect($provider->source())->toBe(AlertSource::GoTransit->value);
});

test('go transit provider selects active alerts by default', function () {
    GoTransitAlert::factory()->create([
        'external_id' => 'GT-1',
        'is_active' => true,
    ]);
    GoTransitAlert::factory()->create([
        'external_id' => 'GT-2',
        'is_active' => false,
    ]);

    $provider = new GoTransitAlertSelectProvider;
    $query = $provider->select(new UnifiedAlertsCriteria(status: AlertStatus::Active->value));

    $results = $query->get();

    expect($results)->toHaveCount(1);
    expect($results[0]->external_id)->toBe('GT-1');
});

test('go transit provider selects cleared alerts', function () {
    GoTransitAlert::factory()->create([
        'external_id' => 'GT-1',
        'is_active' => true,
    ]);
    GoTransitAlert::factory()->create([
        'external_id' => 'GT-2',
        'is_active' => false,
    ]);

    $provider = new GoTransitAlertSelectProvider;
    $query = $provider->select(new UnifiedAlertsCriteria(status: AlertStatus::Cleared->value));

    $results = $query->get();

    expect($results)->toHaveCount(1);
    expect($results[0]->external_id)->toBe('GT-2');
});

test('go transit provider filters by since date', function () {
    $now = Carbon::now();
    GoTransitAlert::factory()->create([
        'posted_at' => $now,
        'is_active' => true,
    ]);
    GoTransitAlert::factory()->create([
        'posted_at' => $now->copy()->subHours(2),
        'is_active' => true,
    ]);

    $provider = new GoTransitAlertSelectProvider;
    $query = $provider->select(new UnifiedAlertsCriteria(
        status: AlertStatus::All->value,
        since: '1h'
    ));

    $results = $query->get();

    expect($results)->toHaveCount(1);
});

test('go transit provider handles source filtering', function () {
    GoTransitAlert::factory()->create();

    $provider = new GoTransitAlertSelectProvider;

    // Should match
    $matchQuery = $provider->select(new UnifiedAlertsCriteria(
        status: AlertStatus::All->value,
        source: 'go_transit'
    ));
    expect($matchQuery->get())->toHaveCount(1);

    // Should not match
    $noMatchQuery = $provider->select(new UnifiedAlertsCriteria(
        status: AlertStatus::All->value,
        source: 'fire'
    ));
    expect($noMatchQuery->get())->toHaveCount(0);
});

test('go transit provider constructs correct meta json expression', function () {
    $alert = GoTransitAlert::factory()->create([
        'alert_type' => 'Service Update',
        'service_mode' => 'Train',
        'sub_category' => 'Delay',
        'corridor_code' => 'LE',
        'direction' => 'East',
        'trip_number' => '123',
        'delay_duration' => 10,
        'line_colour' => '#00FF00',
        'message_body' => 'Test Body',
    ]);

    $provider = new GoTransitAlertSelectProvider;
    $query = $provider->select(new UnifiedAlertsCriteria(status: AlertStatus::All->value));
    $result = $query->first();

    expect($result->meta)->toBeString();
    $meta = json_decode($result->meta, true);

    expect($meta['alert_type'])->toBe('Service Update');
    expect($meta['service_mode'])->toBe('Train');
    expect($meta['trip_number'])->toBe('123');
    expect($meta['message_body'])->toBe('Test Body');
});

test('go transit provider generates mysql fulltext search query', function () {
    DB::shouldReceive('getDriverName')->andReturn('mysql');

    $provider = new GoTransitAlertSelectProvider;
    $query = $provider->select(new UnifiedAlertsCriteria(
        status: AlertStatus::All->value,
        query: 'search term'
    ));

    $sql = $query->toSql();

    expect($sql)->toContain('MATCH(message_subject, message_body, corridor_or_route, corridor_code, service_mode) AGAINST (? IN NATURAL LANGUAGE MODE)');
    expect($sql)->toContain('LOWER(message_subject) LIKE ?');
});
