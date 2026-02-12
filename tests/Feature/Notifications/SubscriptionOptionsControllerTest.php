<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('subscriptions options endpoint requires authentication', function () {
    $this->getJson('/api/subscriptions/options')->assertUnauthorized();
});

test('subscriptions options endpoint returns route, station, and line data', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/subscriptions/options');

    $response
        ->assertOk()
        ->assertJsonPath('data.agency.urn', 'agency:ttc')
        ->assertJsonPath('data.routes.0.urn', 'route:1')
        ->assertJsonPath('data.stations.0.urn', 'station:union')
        ->assertJsonPath('data.lines.0.urn', 'line:1');
});
