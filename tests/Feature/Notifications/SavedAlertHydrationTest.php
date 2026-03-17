<?php

use App\Models\FireIncident;
use App\Models\GoTransitAlert;
use App\Models\PoliceCall;
use App\Models\SavedAlert;
use App\Models\TransitAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('list returns hydrated UnifiedAlertResource format for fire incidents', function () {
    $user = User::factory()->create();
    $fire = FireIncident::factory()->create(['event_num' => 'F26018618']);
    SavedAlert::factory()->create(['user_id' => $user->id, 'alert_id' => 'fire:F26018618']);

    $response = $this->actingAs($user)
        ->getJson('/api/saved-alerts')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonStructure([
            'data' => [
                ['id', 'source', 'external_id', 'is_active', 'timestamp', 'title', 'location', 'meta'],
            ],
            'meta' => ['saved_ids', 'missing_alert_ids'],
        ]);

    expect($response->json('data.0.id'))->toBe('fire:F26018618');
    expect($response->json('data.0.source'))->toBe('fire');
    expect($response->json('data.0.external_id'))->toBe('F26018618');
    expect($response->json('meta.saved_ids'))->toBe(['fire:F26018618']);
    expect($response->json('meta.missing_alert_ids'))->toBe([]);
});

test('list returns hydrated resources for police calls', function () {
    $user = User::factory()->create();
    PoliceCall::factory()->create(['object_id' => 12345]);
    SavedAlert::factory()->create(['user_id' => $user->id, 'alert_id' => 'police:12345']);

    $response = $this->actingAs($user)
        ->getJson('/api/saved-alerts')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect($response->json('data.0.id'))->toBe('police:12345');
    expect($response->json('data.0.source'))->toBe('police');
    expect($response->json('meta.missing_alert_ids'))->toBe([]);
});

test('list returns hydrated resources for transit alerts', function () {
    $user = User::factory()->create();
    TransitAlert::factory()->create(['external_id' => 'api:9999']);
    SavedAlert::factory()->create(['user_id' => $user->id, 'alert_id' => 'transit:api:9999']);

    $response = $this->actingAs($user)
        ->getJson('/api/saved-alerts')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect($response->json('data.0.id'))->toBe('transit:api:9999');
    expect($response->json('data.0.source'))->toBe('transit');
    expect($response->json('meta.missing_alert_ids'))->toBe([]);
});

test('list returns hydrated resources for go transit alerts', function () {
    $user = User::factory()->create();
    GoTransitAlert::factory()->create(['external_id' => 'notif:LW:TDELAY:ABC12345']);
    SavedAlert::factory()->create(['user_id' => $user->id, 'alert_id' => 'go_transit:notif:LW:TDELAY:ABC12345']);

    $response = $this->actingAs($user)
        ->getJson('/api/saved-alerts')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect($response->json('data.0.id'))->toBe('go_transit:notif:LW:TDELAY:ABC12345');
    expect($response->json('data.0.source'))->toBe('go_transit');
    expect($response->json('meta.missing_alert_ids'))->toBe([]);
});

test('list handles mixed sources in a single response', function () {
    $user = User::factory()->create();

    FireIncident::factory()->create(['event_num' => 'F11111111']);
    PoliceCall::factory()->create(['object_id' => 22222]);

    // Saved newest-first order: police first (inserted second), fire second (inserted first)
    SavedAlert::factory()->create(['user_id' => $user->id, 'alert_id' => 'fire:F11111111']);
    SavedAlert::factory()->create(['user_id' => $user->id, 'alert_id' => 'police:22222']);

    $response = $this->actingAs($user)
        ->getJson('/api/saved-alerts')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain('fire:F11111111');
    expect($ids)->toContain('police:22222');
    expect($response->json('meta.saved_ids'))->toContain('fire:F11111111');
    expect($response->json('meta.saved_ids'))->toContain('police:22222');
    expect($response->json('meta.missing_alert_ids'))->toBe([]);
});

test('list places unresolved saved IDs in missing_alert_ids and omits them from data', function () {
    $user = User::factory()->create();

    // Only create a fire incident record, not a police record
    FireIncident::factory()->create(['event_num' => 'F26018618']);
    SavedAlert::factory()->create(['user_id' => $user->id, 'alert_id' => 'fire:F26018618']);
    SavedAlert::factory()->create(['user_id' => $user->id, 'alert_id' => 'police:GHOST99999']);

    $response = $this->actingAs($user)
        ->getJson('/api/saved-alerts')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect($response->json('data.0.id'))->toBe('fire:F26018618');
    expect($response->json('meta.saved_ids'))->toContain('fire:F26018618');
    expect($response->json('meta.saved_ids'))->toContain('police:GHOST99999');
    expect($response->json('meta.missing_alert_ids'))->toContain('police:GHOST99999');
    expect($response->json('meta.missing_alert_ids'))->not->toContain('fire:F26018618');
});

test('list returns all IDs in missing_alert_ids when no underlying records exist', function () {
    $user = User::factory()->create();

    SavedAlert::factory()->create(['user_id' => $user->id, 'alert_id' => 'fire:NORECORD1']);
    SavedAlert::factory()->create(['user_id' => $user->id, 'alert_id' => 'police:99999999']);

    $response = $this->actingAs($user)
        ->getJson('/api/saved-alerts')
        ->assertOk()
        ->assertJsonPath('data', []);

    expect($response->json('meta.missing_alert_ids'))->toContain('fire:NORECORD1');
    expect($response->json('meta.missing_alert_ids'))->toContain('police:99999999');
});

test('list preserves newest-saved-first order in data response', function () {
    $user = User::factory()->create();

    FireIncident::factory()->create(['event_num' => 'FEARLIER']);
    FireIncident::factory()->create(['event_num' => 'FLATEST']);

    // Insert in order: FEARLIER saved first, FLATEST saved second
    SavedAlert::factory()->create(['user_id' => $user->id, 'alert_id' => 'fire:FEARLIER']);
    SavedAlert::factory()->create(['user_id' => $user->id, 'alert_id' => 'fire:FLATEST']);

    $response = $this->actingAs($user)
        ->getJson('/api/saved-alerts')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    // Newest saved first: FLATEST should appear before FEARLIER
    expect($response->json('data.0.id'))->toBe('fire:FLATEST');
    expect($response->json('data.1.id'))->toBe('fire:FEARLIER');
});

test('list returns empty data and proper meta when user has no saved alerts', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/saved-alerts')
        ->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.saved_ids', [])
        ->assertJsonPath('meta.missing_alert_ids', []);
});
