<?php

use App\Models\FireIncident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('manual intel entry sanitizes metadata to prevent Stored XSS', function () {
    $incident = FireIncident::factory()->create();
    $user = User::factory()->create();
    config(['scene_intel.manual_entry.allowed_emails' => [$user->email]]);

    $this
        ->actingAs($user)
        ->postJson("/api/incidents/{$incident->event_num}/intel", [
            'content' => 'Safe content',
            'metadata' => [
                'source' => '<script>alert("XSS")</script>Command',
                'nested' => [
                    'key' => '<b>Bold</b>',
                    'deep' => [
                        'value' => '<img src=x onerror=alert(1)>',
                    ],
                ],
                'safe_int' => 123,
                'safe_bool' => true,
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.metadata.source', 'alert("XSS")Command')
        ->assertJsonPath('data.metadata.nested.key', 'Bold')
        ->assertJsonPath('data.metadata.nested.deep.value', '')
        ->assertJsonPath('data.metadata.safe_int', 123)
        ->assertJsonPath('data.metadata.safe_bool', true);

    $this->assertDatabaseHas('incident_updates', [
        'event_num' => $incident->event_num,
        'metadata->source' => 'alert("XSS")Command',
    ]);
});
