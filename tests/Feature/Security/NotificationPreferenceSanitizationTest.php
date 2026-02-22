<?php

use App\Models\NotificationPreference;
use App\Models\SavedPlace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('notification settings sanitizes geofence names to prevent Stored XSS', function () {
    $user = User::factory()->create();

    $payload = [
        'geofences' => [
            [
                'name' => '<script>alert("XSS")</script>Home',
                'lat' => 43.7001,
                'lng' => -79.4163,
                'radius_km' => 2,
            ],
        ],
    ];

    $response = $this
        ->actingAs($user)
        ->patchJson('/settings/notifications', $payload);

    $response->assertOk();

    // Check SavedPlace directly
    $savedPlace = SavedPlace::query()
        ->where('user_id', $user->id)
        ->where('type', 'legacy_geofence')
        ->first();

    expect($savedPlace->name)->toBe('alert("XSS")Home');
});

test('notification settings sanitizes subscriptions to prevent Stored XSS', function () {
    $user = User::factory()->create();

    $payload = [
        'subscriptions' => ['route:<script>alert(1)</script>', '<b>bold</b>'],
    ];

    $response = $this
        ->actingAs($user)
        ->patchJson('/settings/notifications', $payload);

    $response->assertOk();

    // Check NotificationPreference directly
    $preference = NotificationPreference::query()
        ->where('user_id', $user->id)
        ->first();

    // Note: subscriptions logic adds 'route:' prefix if missing and lowercases
    // So '<b>bold</b>' becomes 'route:<b>bold</b>' (if not sanitized) or 'route:bold' (if sanitized)

    expect($preference->subscriptions)->not->toContain('route:<script>alert(1)</script>');
    expect($preference->subscriptions)->toContain('route:alert(1)');
    expect($preference->subscriptions)->toContain('route:bold');
});
