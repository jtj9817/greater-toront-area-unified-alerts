<?php

use App\Models\FireIncident;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('dashboard returns active fire incidents from the database', function () {
    $user = User::factory()->create();

    $older = FireIncident::factory()->create([
        'event_num' => 'E00000001',
        'event_type' => 'FIRE',
        'dispatch_time' => now()->subHour(),
        'feed_updated_at' => now()->subMinutes(5),
        'is_active' => true,
    ]);

    $newer = FireIncident::factory()->create([
        'event_num' => 'E00000002',
        'event_type' => 'GAS',
        'dispatch_time' => now()->subMinutes(10),
        'feed_updated_at' => now()->subMinutes(2),
        'is_active' => true,
    ]);

    FireIncident::factory()->create([
        'event_num' => 'E00000003',
        'event_type' => 'FIRE',
        'dispatch_time' => now(),
        'feed_updated_at' => now(),
        'is_active' => false,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('active_incidents_count', 2)
            ->has('active_incidents', 2)
            ->where('active_incidents.0.event_num', $newer->event_num)
            ->where('active_incidents.1.event_num', $older->event_num)
            ->where('latest_feed_updated_at', fn ($value) => is_string($value) && $value !== '')
            ->where('active_counts_by_type', function ($value) {
                $types = collect($value);

                return $types->contains(fn ($row) => $row['event_type'] === 'FIRE' && $row['count'] === 1)
                    && $types->contains(fn ($row) => $row['event_type'] === 'GAS' && $row['count'] === 1);
            })
        );
});
