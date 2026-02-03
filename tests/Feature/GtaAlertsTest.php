<?php

use App\Models\FireIncident;
use App\Models\PoliceCall;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
});

test('the home page renders the gta-alerts component', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('gta-alerts')
    );
});

test('the home page provides unified alerts data', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 12:00:00'));

    FireIncident::factory()->create([
        'event_num' => 'E1',
        'is_active' => true,
        'dispatch_time' => Carbon::now()->subMinutes(10),
        'feed_updated_at' => Carbon::now()->subMinutes(9),
    ]);

    FireIncident::factory()->create([
        'event_num' => 'E2',
        'is_active' => false,
        'dispatch_time' => Carbon::now()->subHour(),
        'feed_updated_at' => Carbon::now()->subMinutes(59),
    ]);

    $latest = Carbon::now()->subMinutes(2);
    PoliceCall::factory()->create([
        'object_id' => 123,
        'is_active' => true,
        'occurrence_time' => Carbon::now()->subMinutes(5),
        'feed_updated_at' => $latest,
    ]);

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('gta-alerts')
            ->has('alerts')
            ->has('alerts.data', 3)
            ->where('alerts.data.0.id', 'police:123')
            ->where('alerts.data.1.id', 'fire:E1')
            ->where('alerts.data.2.id', 'fire:E2')
            ->where('latest_feed_updated_at', $latest->toIso8601String())
        );
});

test('the home page allows filtering by status', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 12:00:00'));

    FireIncident::factory()->create([
        'event_num' => 'E1',
        'is_active' => true,
        'dispatch_time' => Carbon::now()->subMinutes(10),
    ]);

    FireIncident::factory()->create([
        'event_num' => 'E2',
        'is_active' => false,
        'dispatch_time' => Carbon::now()->subMinutes(5),
    ]);

    PoliceCall::factory()->create([
        'object_id' => 555,
        'is_active' => true,
        'occurrence_time' => Carbon::now()->subMinutes(1),
    ]);

    $this->get(route('home', ['status' => 'active']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.status', 'active')
            ->has('alerts.data', 2)
            ->where('alerts.data.0.id', 'police:555')
            ->where('alerts.data.1.id', 'fire:E1')
        );
});

test('the home page rejects invalid status values', function () {
    $this->get(route('home', ['status' => 'invalid-status']))
        ->assertRedirect()
        ->assertSessionHasErrors(['status']);
});

test('the home page sets latest_feed_updated_at from fire when police is missing', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 12:00:00'));

    $latest = Carbon::now()->subMinutes(3);
    FireIncident::factory()->create([
        'event_num' => 'E1',
        'is_active' => true,
        'dispatch_time' => Carbon::now()->subMinutes(10),
        'feed_updated_at' => $latest,
    ]);

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('latest_feed_updated_at', $latest->toIso8601String())
        );
});

test('the home page sets latest_feed_updated_at from police when fire is missing', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 12:00:00'));

    $latest = Carbon::now()->subMinutes(3);
    PoliceCall::factory()->create([
        'object_id' => 123,
        'is_active' => true,
        'occurrence_time' => Carbon::now()->subMinutes(10),
        'feed_updated_at' => $latest,
    ]);

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('latest_feed_updated_at', $latest->toIso8601String())
        );
});

test('the home page sets latest_feed_updated_at to the most recent of fire and police', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 12:00:00'));

    $fireLatest = Carbon::now()->subMinutes(2);
    FireIncident::factory()->create([
        'event_num' => 'E1',
        'is_active' => true,
        'dispatch_time' => Carbon::now()->subMinutes(10),
        'feed_updated_at' => $fireLatest,
    ]);

    $policeLatest = Carbon::now()->subMinutes(5);
    PoliceCall::factory()->create([
        'object_id' => 123,
        'is_active' => true,
        'occurrence_time' => Carbon::now()->subMinutes(10),
        'feed_updated_at' => $policeLatest,
    ]);

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('latest_feed_updated_at', $fireLatest->toIso8601String())
        );
});
