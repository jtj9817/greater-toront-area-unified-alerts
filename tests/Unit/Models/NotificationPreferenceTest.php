<?php

use App\Models\NotificationPreference;
use Illuminate\Support\Facades\Validator;

uses(Tests\TestCase::class);

test('it has expected fillable attributes and casts', function () {
    $preference = new NotificationPreference([
        'subscriptions' => ['route:1', 'route:501'],
        'digest_mode' => 1,
        'push_enabled' => 0,
    ]);

    expect($preference->getFillable())->toBe([
        'user_id',
        'alert_type',
        'severity_threshold',
        'subscriptions',
        'digest_mode',
        'push_enabled',
    ]);

    expect($preference->subscriptions)->toBeArray();
    expect($preference->digest_mode)->toBeTrue();
    expect($preference->push_enabled)->toBeFalse();
});

test('preference validation rules accept valid payload', function () {
    $validator = Validator::make([
        'alert_type' => 'transit',
        'severity_threshold' => 'major',
        'geofences' => [
            ['name' => 'Home', 'lat' => 43.7001, 'lng' => -79.4163, 'radius_km' => 1.5],
        ],
        'subscriptions' => ['route:1', 'route:go-lw'],
        'digest_mode' => false,
        'push_enabled' => true,
    ], NotificationPreference::validationRules());

    expect($validator->fails())->toBeFalse();
});

test('preference validation rules reject invalid payload', function () {
    $validator = Validator::make([
        'alert_type' => 'invalid-type',
        'severity_threshold' => 'urgent',
        'geofences' => ['invalid-shape'],
        'subscriptions' => [123],
        'digest_mode' => 'yes',
        'push_enabled' => 'sometimes',
    ], NotificationPreference::validationRules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->keys())->toContain(
        'alert_type',
        'severity_threshold',
        'geofences.0',
        'subscriptions.0',
        'digest_mode',
        'push_enabled',
    );
});

test('preference validation rules require complete geofence coordinates and radius', function () {
    $validator = Validator::make([
        'alert_type' => 'all',
        'severity_threshold' => 'all',
        'geofences' => [
            ['name' => 'Incomplete Geofence'],
        ],
        'subscriptions' => [],
        'digest_mode' => false,
        'push_enabled' => true,
    ], NotificationPreference::validationRules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->keys())->toContain(
        'geofences.0.lat',
        'geofences.0.lng',
        'geofences.0.radius_km',
    );
});
