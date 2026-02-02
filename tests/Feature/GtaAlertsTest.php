<?php

use App\Models\FireIncident;
use Inertia\Testing\AssertableInertia as Assert;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('the home page renders the gta-alerts component', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('gta-alerts')
    );
});

test('the home page provides fire incidents data', function () {
    FireIncident::factory()->create([
        'event_num' => 'E1',
        'is_active' => true,
        'dispatch_time' => now()->subMinutes(10),
    ]);

    FireIncident::factory()->create([
        'event_num' => 'E2',
        'is_active' => false,
    ]);

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('gta-alerts')
            ->has('incidents')
            ->has('incidents.data', 1)
            ->where('incidents.data.0.event_num', 'E1')
        );
});

test('the home page allows filtering by search query', function () {
    FireIncident::factory()->create([
        'event_num' => 'E1',
        'prime_street' => 'MAIN ST',
        'is_active' => true,
    ]);

    FireIncident::factory()->create([
        'event_num' => 'E2',
        'prime_street' => 'OTHER ST',
        'is_active' => true,
    ]);

    $this->get(route('home', ['search' => 'MAIN']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('incidents.data', 1)
            ->where('incidents.data.0.event_num', 'E1')
        );
});
