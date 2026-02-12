<?php

use App\Models\NotificationPreference;
use App\Models\SavedPlace;
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
        ->assertJsonPath('data.subscriptions', [])
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
        'subscriptions' => ['route:1', 'route:501', 'route:go-lw'],
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
        ->assertJsonPath('data.subscriptions', ['route:1', 'route:501', 'route:go-lw'])
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
            'subscriptions' => [123],
            'digest_mode' => 'yes',
        ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'severity_threshold',
            'geofences.0',
            'subscriptions.0',
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
        'subscriptions' => ['route:go-ki'],
    ]);

    $this->actingAs($user)->patchJson('/settings/notifications', [
        'alert_type' => 'transit',
        'severity_threshold' => 'minor',
        'subscriptions' => ['route:1'],
    ])->assertOk();

    expect(NotificationPreference::query()->where('user_id', $otherUser->id)->firstOrFail()->severity_threshold)
        ->toBe('critical');
    expect(NotificationPreference::query()->where('user_id', $user->id)->firstOrFail()->severity_threshold)
        ->toBe('minor');
});

test('notification settings update rejects invalid alert type and push toggle', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patchJson('/settings/notifications', [
            'alert_type' => 'weather',
            'push_enabled' => 'enabled',
        ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'alert_type',
            'push_enabled',
        ]);
});

test('notification settings update enforces geofence coordinate and radius boundaries', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->patchJson('/settings/notifications', [
            'geofences' => [
                ['name' => 'Boundary', 'lat' => 90, 'lng' => 180, 'radius_km' => 100],
            ],
        ])
        ->assertOk();

    $response = $this
        ->actingAs($user)
        ->patchJson('/settings/notifications', [
            'geofences' => [
                ['name' => 'Invalid A', 'lat' => 90.0001, 'lng' => 180, 'radius_km' => 1],
                ['name' => 'Invalid B', 'lat' => 43.7, 'lng' => -180.0001, 'radius_km' => 1],
                ['name' => 'Invalid C', 'lat' => 43.7, 'lng' => -79.4, 'radius_km' => 0],
            ],
        ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'geofences.0.lat',
            'geofences.1.lng',
            'geofences.2.radius_km',
        ]);
});

test('notification settings update requires complete geofence coordinates and radius', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patchJson('/settings/notifications', [
            'geofences' => [
                ['name' => 'Incomplete Geofence'],
            ],
        ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'geofences.0.lat',
            'geofences.0.lng',
            'geofences.0.radius_km',
        ]);
});

test('partial notification settings patch preserves untouched fields', function () {
    $user = User::factory()->create();

    NotificationPreference::factory()->create([
        'user_id' => $user->id,
        'alert_type' => 'transit',
        'severity_threshold' => 'major',
        'subscriptions' => ['route:1', 'route:go-lw'],
        'digest_mode' => false,
        'push_enabled' => true,
    ]);

    SavedPlace::factory()->create([
        'user_id' => $user->id,
        'name' => 'Home',
        'lat' => 43.7,
        'long' => -79.4,
        'radius' => 2000,
        'type' => 'legacy_geofence',
    ]);

    $this
        ->actingAs($user)
        ->patchJson('/settings/notifications', [
            'severity_threshold' => 'critical',
        ])
        ->assertOk()
        ->assertJsonPath('data.alert_type', 'transit')
        ->assertJsonPath('data.severity_threshold', 'critical')
        ->assertJsonPath('data.subscriptions', ['route:1', 'route:go-lw'])
        ->assertJsonPath('data.digest_mode', false)
        ->assertJsonPath('data.push_enabled', true);
});

test('notification settings update accepts empty arrays for geofences and subscribed routes', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->patchJson('/settings/notifications', [
            'geofences' => [],
            'subscriptions' => [],
        ])
        ->assertOk()
        ->assertJsonPath('data.geofences', [])
        ->assertJsonPath('data.subscriptions', []);
});

test('notification settings update rejects null scalar preference fields', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patchJson('/settings/notifications', [
            'alert_type' => null,
            'severity_threshold' => null,
            'digest_mode' => null,
            'push_enabled' => null,
        ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'alert_type',
            'severity_threshold',
            'digest_mode',
            'push_enabled',
        ]);
});

test('notification settings update rejects unknown geofence keys', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patchJson('/settings/notifications', [
            'geofences' => [
                [
                    'name' => 'Home',
                    'lat' => 43.7,
                    'lng' => -79.4,
                    'radius_km' => 2,
                    'extra' => 'not-allowed',
                ],
            ],
        ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'geofences.0',
        ]);
});

test('repeated notification settings fetch and update do not create duplicate preference rows', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/settings/notifications')->assertOk();
    $this->actingAs($user)->getJson('/settings/notifications')->assertOk();
    $this->actingAs($user)->patchJson('/settings/notifications', ['digest_mode' => true])->assertOk();
    $this->actingAs($user)->patchJson('/settings/notifications', ['digest_mode' => false])->assertOk();

    expect(NotificationPreference::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('notification settings geofences are synced to legacy saved places', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patchJson('/settings/notifications', [
            'geofences' => [
                ['name' => 'Home', 'lat' => 43.7001, 'lng' => -79.4163, 'radius_km' => 1.2],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.geofences.0.name', 'Home');

    $savedPlace = SavedPlace::query()->where('user_id', $user->id)->firstOrFail();

    expect($savedPlace->type)->toBe('legacy_geofence');
    expect($savedPlace->lat)->toBe(43.7001);
    expect($savedPlace->long)->toBe(-79.4163);
    expect($savedPlace->radius)->toBe(1200);
});
