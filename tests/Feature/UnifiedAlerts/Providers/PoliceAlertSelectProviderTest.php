<?php

use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\PoliceCall;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use App\Services\Alerts\Providers\PoliceAlertSelectProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('police provider returns source name', function () {
    $provider = new PoliceAlertSelectProvider;
    expect($provider->source())->toBe(AlertSource::Police->value);
});

test('police provider selects active alerts by default', function () {
    PoliceCall::factory()->create([
        'object_id' => 100,
        'is_active' => true,
    ]);
    PoliceCall::factory()->create([
        'object_id' => 200,
        'is_active' => false,
    ]);

    $provider = new PoliceAlertSelectProvider;
    $query = $provider->select(new UnifiedAlertsCriteria(status: AlertStatus::Active->value));

    $results = $query->get();

    expect($results)->toHaveCount(1);
    expect($results[0]->external_id)->toBe('100');
});

test('police provider selects cleared alerts', function () {
    PoliceCall::factory()->create([
        'object_id' => 100,
        'is_active' => true,
    ]);
    PoliceCall::factory()->create([
        'object_id' => 200,
        'is_active' => false,
    ]);

    $provider = new PoliceAlertSelectProvider;
    $query = $provider->select(new UnifiedAlertsCriteria(status: AlertStatus::Cleared->value));

    $results = $query->get();

    expect($results)->toHaveCount(1);
    expect($results[0]->external_id)->toBe('200');
});

test('police provider filters by since date', function () {
    $now = Carbon::now();
    PoliceCall::factory()->create([
        'occurrence_time' => $now,
        'is_active' => true,
    ]);
    PoliceCall::factory()->create([
        'occurrence_time' => $now->copy()->subHours(2),
        'is_active' => true,
    ]);

    $provider = new PoliceAlertSelectProvider;
    $query = $provider->select(new UnifiedAlertsCriteria(
        status: AlertStatus::All->value,
        since: '1h'
    ));

    $results = $query->get();

    expect($results)->toHaveCount(1);
});

test('police provider handles source filtering', function () {
    PoliceCall::factory()->create();

    $provider = new PoliceAlertSelectProvider;

    // Should match
    $matchQuery = $provider->select(new UnifiedAlertsCriteria(
        status: AlertStatus::All->value,
        source: 'police'
    ));
    expect($matchQuery->get())->toHaveCount(1);

    // Should not match
    $noMatchQuery = $provider->select(new UnifiedAlertsCriteria(
        status: AlertStatus::All->value,
        source: 'fire'
    ));
    expect($noMatchQuery->get())->toHaveCount(0);
});

test('police provider constructs correct meta json expression', function () {
    $call = PoliceCall::factory()->create([
        'object_id' => 12345,
        'division' => 'D51',
        'call_type_code' => 'THEFT',
    ]);

    $provider = new PoliceAlertSelectProvider;
    $query = $provider->select(new UnifiedAlertsCriteria(status: AlertStatus::All->value));
    $result = $query->first();

    expect($result->meta)->toBeString();
    $meta = json_decode($result->meta, true);

    expect($meta['object_id'])->toBe(12345);
    expect($meta['division'])->toBe('D51');
    expect($meta['call_type_code'])->toBe('THEFT');
});
