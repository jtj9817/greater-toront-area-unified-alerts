<?php

use App\Models\NotificationLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('notification inbox endpoints require authentication', function () {
    $log = NotificationLog::factory()->create();

    $this->getJson('/notifications/inbox')->assertUnauthorized();
    $this->patchJson("/notifications/inbox/{$log->id}/read")->assertUnauthorized();
    $this->patchJson("/notifications/inbox/{$log->id}/dismiss")->assertUnauthorized();
    $this->deleteJson('/notifications/inbox')->assertUnauthorized();
});

test('authenticated user can list their own inbox logs including digest entries', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $alertLog = NotificationLog::factory()->create([
        'user_id' => $user->id,
        'alert_id' => 'police:101',
        'delivery_method' => 'in_app',
        'status' => 'delivered',
        'sent_at' => CarbonImmutable::parse('2026-02-10T10:00:00Z'),
        'read_at' => null,
        'dismissed_at' => null,
        'metadata' => [
            'source' => 'police',
            'severity' => 'major',
            'summary' => 'Police response in progress',
        ],
    ]);

    $digestLog = NotificationLog::factory()->create([
        'user_id' => $user->id,
        'alert_id' => 'digest:2026-02-10',
        'delivery_method' => 'in_app_digest',
        'status' => 'sent',
        'sent_at' => CarbonImmutable::parse('2026-02-10T12:00:00Z'),
        'read_at' => null,
        'dismissed_at' => null,
        'metadata' => [
            'type' => 'daily_digest',
            'digest_date' => '2026-02-10',
            'total_notifications' => 4,
        ],
    ]);

    $dismissedLog = NotificationLog::factory()->create([
        'user_id' => $user->id,
        'delivery_method' => 'in_app',
        'status' => 'dismissed',
        'sent_at' => CarbonImmutable::parse('2026-02-10T11:00:00Z'),
        'read_at' => CarbonImmutable::parse('2026-02-10T11:00:30Z'),
        'dismissed_at' => CarbonImmutable::parse('2026-02-10T11:01:00Z'),
    ]);

    NotificationLog::factory()->create([
        'user_id' => $otherUser->id,
        'delivery_method' => 'in_app',
        'status' => 'delivered',
        'read_at' => null,
        'dismissed_at' => null,
        'sent_at' => CarbonImmutable::parse('2026-02-10T13:00:00Z'),
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/notifications/inbox');

    $response
        ->assertOk()
        ->assertJsonPath('meta.unread_count', 2)
        ->assertJsonPath('data.0.id', $digestLog->id)
        ->assertJsonPath('data.0.type', 'digest')
        ->assertJsonPath('data.1.id', $alertLog->id)
        ->assertJsonPath('data.1.type', 'alert');

    $listedIds = collect($response->json('data'))->pluck('id')->all();

    expect($listedIds)->toContain($alertLog->id);
    expect($listedIds)->toContain($digestLog->id);
    expect($listedIds)->not->toContain($dismissedLog->id);
});

test('pagination links preserve applied inbox query filters', function () {
    $user = User::factory()->create();

    NotificationLog::factory()->create([
        'user_id' => $user->id,
        'status' => 'delivered',
        'sent_at' => CarbonImmutable::parse('2026-02-10T10:00:00Z'),
        'read_at' => null,
        'dismissed_at' => null,
    ]);

    NotificationLog::factory()->create([
        'user_id' => $user->id,
        'status' => 'delivered',
        'sent_at' => CarbonImmutable::parse('2026-02-10T11:00:00Z'),
        'read_at' => null,
        'dismissed_at' => null,
    ]);

    NotificationLog::factory()->create([
        'user_id' => $user->id,
        'status' => 'dismissed',
        'sent_at' => CarbonImmutable::parse('2026-02-10T12:00:00Z'),
        'read_at' => CarbonImmutable::parse('2026-02-10T12:00:30Z'),
        'dismissed_at' => CarbonImmutable::parse('2026-02-10T12:01:00Z'),
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/notifications/inbox?include_dismissed=1&per_page=1&page=1');

    $response
        ->assertOk()
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.total', 3);

    $nextLink = $response->json('links.next');

    expect($nextLink)->not->toBeNull();
    expect($nextLink)->toContain('include_dismissed=1');
    expect($nextLink)->toContain('per_page=1');
});

test('authenticated user can mark their inbox log as read', function () {
    $user = User::factory()->create();

    $log = NotificationLog::factory()->create([
        'user_id' => $user->id,
        'status' => 'delivered',
        'read_at' => null,
        'dismissed_at' => null,
    ]);

    $response = $this
        ->actingAs($user)
        ->patchJson("/notifications/inbox/{$log->id}/read");

    $response
        ->assertOk()
        ->assertJsonPath('data.id', $log->id)
        ->assertJsonPath('data.status', 'read');

    $log->refresh();

    expect($log->read_at)->not->toBeNull();
    expect($log->status)->toBe('read');
});

test('mark as read enforces ownership boundaries', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $otherUserLog = NotificationLog::factory()->create([
        'user_id' => $otherUser->id,
        'status' => 'delivered',
        'read_at' => null,
        'dismissed_at' => null,
    ]);

    $this
        ->actingAs($user)
        ->patchJson("/notifications/inbox/{$otherUserLog->id}/read")
        ->assertNotFound();

    $otherUserLog->refresh();

    expect($otherUserLog->read_at)->toBeNull();
    expect($otherUserLog->status)->toBe('delivered');
});

test('authenticated user can dismiss an inbox log', function () {
    $user = User::factory()->create();

    $log = NotificationLog::factory()->create([
        'user_id' => $user->id,
        'status' => 'delivered',
        'read_at' => null,
        'dismissed_at' => null,
    ]);

    $response = $this
        ->actingAs($user)
        ->patchJson("/notifications/inbox/{$log->id}/dismiss");

    $response
        ->assertOk()
        ->assertJsonPath('data.id', $log->id)
        ->assertJsonPath('data.status', 'dismissed');

    $log->refresh();

    expect($log->dismissed_at)->not->toBeNull();
    expect($log->read_at)->not->toBeNull();
    expect($log->status)->toBe('dismissed');
});

test('dismiss enforces ownership boundaries', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $otherUserLog = NotificationLog::factory()->create([
        'user_id' => $otherUser->id,
        'status' => 'delivered',
        'read_at' => null,
        'dismissed_at' => null,
    ]);

    $this
        ->actingAs($user)
        ->patchJson("/notifications/inbox/{$otherUserLog->id}/dismiss")
        ->assertNotFound();

    $otherUserLog->refresh();

    expect($otherUserLog->dismissed_at)->toBeNull();
    expect($otherUserLog->status)->toBe('delivered');
});

test('authenticated user can clear all undismissed inbox logs', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $existingReadAt = CarbonImmutable::parse('2026-02-10T10:00:00Z');

    $toClearOne = NotificationLog::factory()->create([
        'user_id' => $user->id,
        'status' => 'delivered',
        'read_at' => null,
        'dismissed_at' => null,
    ]);

    $toClearTwo = NotificationLog::factory()->create([
        'user_id' => $user->id,
        'status' => 'read',
        'read_at' => $existingReadAt,
        'dismissed_at' => null,
    ]);

    $alreadyDismissed = NotificationLog::factory()->create([
        'user_id' => $user->id,
        'status' => 'dismissed',
        'read_at' => CarbonImmutable::parse('2026-02-10T10:05:00Z'),
        'dismissed_at' => CarbonImmutable::parse('2026-02-10T10:06:00Z'),
    ]);

    $otherUserLog = NotificationLog::factory()->create([
        'user_id' => $otherUser->id,
        'status' => 'delivered',
        'read_at' => null,
        'dismissed_at' => null,
    ]);

    $this
        ->actingAs($user)
        ->deleteJson('/notifications/inbox')
        ->assertOk()
        ->assertJsonPath('meta.dismissed_count', 2)
        ->assertJsonPath('meta.unread_count', 0);

    $toClearOne->refresh();
    $toClearTwo->refresh();
    $alreadyDismissed->refresh();
    $otherUserLog->refresh();

    expect($toClearOne->dismissed_at)->not->toBeNull();
    expect($toClearOne->read_at)->not->toBeNull();
    expect($toClearOne->status)->toBe('dismissed');

    expect($toClearTwo->dismissed_at)->not->toBeNull();
    expect($toClearTwo->read_at?->toISOString())->toBe($existingReadAt->toISOString());
    expect($toClearTwo->status)->toBe('dismissed');

    expect($alreadyDismissed->status)->toBe('dismissed');
    expect($otherUserLog->dismissed_at)->toBeNull();
    expect($otherUserLog->status)->toBe('delivered');
});
