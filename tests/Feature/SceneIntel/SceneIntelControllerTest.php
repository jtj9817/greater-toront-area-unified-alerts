<?php

use App\Enums\IncidentUpdateType;
use App\Models\FireIncident;
use App\Models\IncidentUpdate;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('public timeline endpoint returns chronological scene intel for an incident', function () {
    $incident = FireIncident::factory()->create([
        'event_num' => 'F26050001',
    ]);

    IncidentUpdate::factory()->create([
        'event_num' => $incident->event_num,
        'update_type' => IncidentUpdateType::RESOURCE_STATUS,
        'content' => 'Unit P144 dispatched',
        'metadata' => ['unit_code' => 'P144', 'status' => 'dispatched'],
        'created_at' => Carbon::parse('2026-02-14 10:01:00'),
    ]);

    IncidentUpdate::factory()->create([
        'event_num' => $incident->event_num,
        'update_type' => IncidentUpdateType::ALARM_CHANGE,
        'content' => 'Alarm level increased from 1 to 2',
        'metadata' => ['previous_level' => 1, 'new_level' => 2, 'direction' => 'up'],
        'created_at' => Carbon::parse('2026-02-14 10:00:00'),
    ]);

    $response = $this->getJson("/api/incidents/{$incident->event_num}/intel");

    $response
        ->assertOk()
        ->assertJsonPath('meta.event_num', $incident->event_num)
        ->assertJsonPath('meta.count', 2)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.type', 'alarm_change')
        ->assertJsonPath('data.0.type_label', 'Alarm Level Change')
        ->assertJsonPath('data.0.icon', 'trending_up')
        ->assertJsonPath('data.0.content', 'Alarm level increased from 1 to 2')
        ->assertJsonPath('data.0.metadata.previous_level', 1)
        ->assertJsonPath('data.1.type', 'resource_status')
        ->assertJsonPath('data.1.content', 'Unit P144 dispatched')
        ->assertJsonPath('data.1.metadata.status', 'dispatched');
});

test('public timeline endpoint returns not found for unknown incident', function () {
    $this->getJson('/api/incidents/F26059999/intel')->assertNotFound();
});

test('manual intel entry endpoint requires authentication', function () {
    $incident = FireIncident::factory()->create();

    $this->postJson("/api/incidents/{$incident->event_num}/intel", [
        'content' => 'Primary search complete',
    ])->assertUnauthorized();
});

test('manual intel entry endpoint requires verified users', function () {
    $incident = FireIncident::factory()->create();
    $user = User::factory()->unverified()->create();

    $this
        ->actingAs($user)
        ->postJson("/api/incidents/{$incident->event_num}/intel", [
            'content' => 'Primary search complete',
        ])
        ->assertForbidden();
});

test('verified user is denied when allowlist is empty', function () {
    $incident = FireIncident::factory()->create([
        'event_num' => 'F26050002',
    ]);
    $user = User::factory()->create([
        'email' => 'verified.user@example.test',
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson("/api/incidents/{$incident->event_num}/intel", [
            'content' => '  Fire is under control  ',
            'metadata' => ['note_source' => 'command'],
        ]);

    $response->assertForbidden();

    $this->assertDatabaseMissing('incident_updates', [
        'event_num' => $incident->event_num,
        'content' => 'Fire is under control',
    ]);
});

test('manual intel entry endpoint validates payload', function () {
    $incident = FireIncident::factory()->create();
    $user = User::factory()->create();
    config(['scene_intel.manual_entry.allowed_emails' => [$user->email]]);

    $this
        ->actingAs($user)
        ->postJson("/api/incidents/{$incident->event_num}/intel", [
            'content' => '   ',
            'metadata' => 'not-an-array',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'content',
            'metadata',
        ]);
});

test('manual intel entry endpoint returns validation error for non-string content', function () {
    $incident = FireIncident::factory()->create();
    $user = User::factory()->create();
    config(['scene_intel.manual_entry.allowed_emails' => [$user->email]]);

    $this
        ->actingAs($user)
        ->postJson("/api/incidents/{$incident->event_num}/intel", [
            'content' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'content',
        ]);
});

test('manual intel entry endpoint returns not found when incident does not exist', function () {
    $user = User::factory()->create();
    config(['scene_intel.manual_entry.allowed_emails' => [$user->email]]);

    $this
        ->actingAs($user)
        ->postJson('/api/incidents/F26058888/intel', [
            'content' => 'Manual note for missing incident',
        ])
        ->assertNotFound();
});

test('manual intel entry gate can deny users when allowlist is configured', function () {
    config(['scene_intel.manual_entry.allowed_emails' => ['dispatcher@example.test']]);

    $incident = FireIncident::factory()->create();
    $user = User::factory()->create([
        'email' => 'analyst@example.test',
    ]);

    $this
        ->actingAs($user)
        ->postJson("/api/incidents/{$incident->event_num}/intel", [
            'content' => 'Should be forbidden',
        ])
        ->assertForbidden();
});

test('manual intel entry gate allows configured allowlist users', function () {
    config(['scene_intel.manual_entry.allowed_emails' => ['dispatcher@example.test']]);

    $incident = FireIncident::factory()->create();
    $user = User::factory()->create([
        'email' => 'dispatcher@example.test',
    ]);

    $this
        ->actingAs($user)
        ->postJson("/api/incidents/{$incident->event_num}/intel", [
            'content' => 'Dispatcher note',
        ])
        ->assertCreated()
        ->assertJsonPath('data.content', 'Dispatcher note');
});

test('manual intel entry strips html tags from content', function () {
    $incident = FireIncident::factory()->create();
    $user = User::factory()->create();
    config(['scene_intel.manual_entry.allowed_emails' => [$user->email]]);

    $this
        ->actingAs($user)
        ->postJson("/api/incidents/{$incident->event_num}/intel", [
            'content' => '<script>alert("XSS")</script>Fire is <b>active</b>',
        ])
        ->assertCreated()
        ->assertJsonPath('data.content', 'alert("XSS")Fire is active');

    $this->assertDatabaseHas('incident_updates', [
        'event_num' => $incident->event_num,
        'content' => 'alert("XSS")Fire is active',
    ]);
});
