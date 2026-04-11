<?php

use App\Models\FireIncident;
use App\Models\PoliceCall;
use App\Models\SavedAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('saved alerts endpoints require authentication', function () {
    $this->getJson('/api/saved-alerts')->assertUnauthorized();
    $this->postJson('/api/saved-alerts', [])->assertUnauthorized();
    $this->deleteJson('/api/saved-alerts/fire:F26018618')->assertUnauthorized();
});

test('authenticated user can save an alert', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/saved-alerts', ['alert_id' => 'fire:F26018618'])
        ->assertCreated()
        ->assertJsonPath('data.alert_id', 'fire:F26018618');

    $this->assertDatabaseHas('saved_alerts', [
        'user_id' => $user->id,
        'alert_id' => 'fire:F26018618',
    ]);
});

test('authenticated user can save alerts from all sources', function () {
    $user = User::factory()->create();

    $sources = [
        'fire:F26018618',
        'police:P12345678',
        'transit:T99887766',
        'go_transit:GT001234',
    ];

    foreach ($sources as $alertId) {
        $this->actingAs($user)
            ->postJson('/api/saved-alerts', ['alert_id' => $alertId])
            ->assertCreated()
            ->assertJsonPath('data.alert_id', $alertId);
    }

    expect(SavedAlert::query()->where('user_id', $user->id)->count())->toBe(4);
});

test('duplicate save returns 409 conflict', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/saved-alerts', ['alert_id' => 'fire:F26018618'])
        ->assertCreated();

    $this->actingAs($user)
        ->postJson('/api/saved-alerts', ['alert_id' => 'fire:F26018618'])
        ->assertStatus(409);

    expect(SavedAlert::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('authenticated user can list saved alerts with hydrated resources', function () {
    $user = User::factory()->create();

    FireIncident::factory()->create(['event_num' => 'F26018618']);
    PoliceCall::factory()->create(['object_id' => 12345678]);

    // Insert fire first, then police — so police is newer saved
    SavedAlert::factory()->create(['user_id' => $user->id, 'alert_id' => 'fire:F26018618']);
    SavedAlert::factory()->create(['user_id' => $user->id, 'alert_id' => 'police:12345678']);

    $response = $this->actingAs($user)
        ->getJson('/api/saved-alerts')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure([
            'data' => [
                ['id', 'source', 'external_id', 'is_active', 'timestamp', 'title', 'location', 'meta'],
            ],
            'meta' => ['saved_ids', 'missing_alert_ids'],
        ]);

    $savedIds = $response->json('meta.saved_ids');
    expect($savedIds)->toContain('fire:F26018618');
    expect($savedIds)->toContain('police:12345678');
    expect($response->json('meta.missing_alert_ids'))->toBe([]);

    // `index()` returns results in newest-saved-first order (police saved last → first in response).
    expect($response->json('data.0.id'))->toBe('police:12345678');
    expect($response->json('data.1.id'))->toBe('fire:F26018618');
});

test('list returns empty data and meta for user with no saved alerts', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/saved-alerts')
        ->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.saved_ids', [])
        ->assertJsonPath('meta.missing_alert_ids', []);
});

test('authenticated user can delete a saved alert', function () {
    $user = User::factory()->create();

    SavedAlert::factory()->create(['user_id' => $user->id, 'alert_id' => 'fire:F26018618']);

    $this->actingAs($user)
        ->deleteJson('/api/saved-alerts/fire:F26018618')
        ->assertOk()
        ->assertJsonPath('meta.deleted', true);

    $this->assertDatabaseMissing('saved_alerts', [
        'user_id' => $user->id,
        'alert_id' => 'fire:F26018618',
    ]);
});

test('delete returns 404 when alert is not saved', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->deleteJson('/api/saved-alerts/fire:F26018618')
        ->assertNotFound();
});

test('delete is scoped to the owner', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    SavedAlert::factory()->create(['user_id' => $owner->id, 'alert_id' => 'fire:F26018618']);

    $this->actingAs($otherUser)
        ->deleteJson('/api/saved-alerts/fire:F26018618')
        ->assertNotFound();

    $this->assertDatabaseHas('saved_alerts', [
        'user_id' => $owner->id,
        'alert_id' => 'fire:F26018618',
    ]);
});

test('list is scoped to the authenticated user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    SavedAlert::factory()->create(['user_id' => $user->id, 'alert_id' => 'fire:F26018618']);
    SavedAlert::factory()->create(['user_id' => $otherUser->id, 'alert_id' => 'police:P12345678']);

    $response = $this->actingAs($user)
        ->getJson('/api/saved-alerts')
        ->assertOk();

    $savedIds = $response->json('meta.saved_ids');
    expect($savedIds)->toContain('fire:F26018618');
    expect($savedIds)->not->toContain('police:P12345678');
    expect($savedIds)->toHaveCount(1);
});

test('store rejects missing alert_id', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/saved-alerts', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['alert_id']);
});

test('store rejects alert_id without colon separator', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/saved-alerts', ['alert_id' => 'fireF26018618'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['alert_id']);
});

test('store rejects alert_id with invalid source', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/saved-alerts', ['alert_id' => 'unknown:F26018618'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['alert_id']);
});

test('store rejects alert_id with empty externalId', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/saved-alerts', ['alert_id' => 'fire:'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['alert_id']);
});

test('store rejects non-string alert_id values', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/saved-alerts', ['alert_id' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['alert_id']);
});

test('store normalizes whitespace around alert_id before validation', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/saved-alerts', ['alert_id' => '  fire:F26018618  '])
        ->assertCreated()
        ->assertJsonPath('data.alert_id', 'fire:F26018618');
});

test('store sanitizes alert_id to prevent Stored XSS', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/saved-alerts', [
        'alert_id' => '  <script></script>yrt:12345  ',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.alert_id', 'yrt:12345');
});

test('authenticated saves are uncapped in this iteration', function () {
    $user = User::factory()->create();

    $sources = ['fire', 'police', 'transit', 'go_transit'];
    $count = 25;

    for ($i = 1; $i <= $count; $i++) {
        $source = $sources[($i - 1) % count($sources)];
        $this->actingAs($user)
            ->postJson('/api/saved-alerts', ['alert_id' => "{$source}:ID{$i}"])
            ->assertCreated();
    }

    expect(SavedAlert::query()->where('user_id', $user->id)->count())->toBe($count);
});
