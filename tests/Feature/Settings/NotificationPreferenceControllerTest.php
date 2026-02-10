<?php

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('notification settings endpoints require authentication', function () {
    $this->getJson('/settings/notifications')->assertUnauthorized();
    $this->patchJson('/settings/notifications', [])->assertUnauthorized();
});

test('authenticated user can fetch notification settings defaults', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->getJson('/settings/notifications');

    $response
        ->assertOk()
        ->assertJsonPath('data.alert_type', 'all')
        ->assertJsonPath('data.severity_threshold', 'all')
        ->assertJsonPath('data.geofences', [])
        ->assertJsonPath('data.subscribed_routes', [])
        ->assertJsonPath('data.digest_mode', false)
        ->assertJsonPath('data.push_enabled', true);

    $this->assertDatabaseHas('notification_preferences', [
        'user_id' => $user->id,
        'alert_type' => 'all',
        'severity_threshold' => 'all',
        'digest_mode' => false,
        'push_enabled' => true,
    ]);
});

test('authenticated user can update notification settings', function () {
    $user = User::factory()->create();

    $payload = [
        'alert_type' => 'transit',
        'severity_threshold' => 'major',
        'geofences' => [
            ['name' => 'Home', 'lat' => 43.7001, 'lng' => -79.4163, 'radius_km' => 2],
        ],
        'subscribed_routes' => ['1', '501', 'GO-LW'],
        'digest_mode' => true,
        'push_enabled' => false,
    ];

    $response = $this
        ->actingAs($user)
        ->patchJson('/settings/notifications', $payload);

    $response
        ->assertOk()
        ->assertJsonPath('data.alert_type', 'transit')
        ->assertJsonPath('data.severity_threshold', 'major')
        ->assertJsonPath('data.subscribed_routes', ['1', '501', 'GO-LW'])
        ->assertJsonPath('data.digest_mode', true)
        ->assertJsonPath('data.push_enabled', false);

    $this->assertDatabaseHas('notification_preferences', [
        'user_id' => $user->id,
        'alert_type' => 'transit',
        'severity_threshold' => 'major',
        'digest_mode' => true,
        'push_enabled' => false,
    ]);
});

test('notification settings update validates payload', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patchJson('/settings/notifications', [
            'severity_threshold' => 'urgent',
            'geofences' => ['invalid-geofence-shape'],
            'subscribed_routes' => [123],
            'digest_mode' => 'yes',
        ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'severity_threshold',
            'geofences.0',
            'subscribed_routes.0',
            'digest_mode',
        ]);
});

test('notification settings updates only affect the authenticated user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    NotificationPreference::factory()->create([
        'user_id' => $otherUser->id,
        'alert_type' => 'emergency',
        'severity_threshold' => 'critical',
        'subscribed_routes' => ['GO-KI'],
    ]);

    $this->actingAs($user)->patchJson('/settings/notifications', [
        'alert_type' => 'transit',
        'severity_threshold' => 'minor',
        'subscribed_routes' => ['1'],
    ])->assertOk();

    expect(NotificationPreference::query()->where('user_id', $otherUser->id)->firstOrFail()->severity_threshold)
        ->toBe('critical');
    expect(NotificationPreference::query()->where('user_id', $user->id)->firstOrFail()->severity_threshold)
        ->toBe('minor');
});
