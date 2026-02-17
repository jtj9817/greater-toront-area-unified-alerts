<?php

use App\Events\AlertCreated;
use App\Models\PoliceCall;
use App\Services\TorontoPoliceFeedService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('it creates new records from feed data', function () {
    $this->mock(TorontoPoliceFeedService::class, function (MockInterface $mock) {
        $mock->shouldReceive('fetch')->once()->andReturn([
            [
                'object_id' => 123,
                'call_type_code' => 'BREPR',
                'call_type' => 'BREAK & ENTER IN PROGRESS',
                'division' => 'D42',
                'cross_streets' => 'BAY ST - YORK ST',
                'latitude' => 43.65,
                'longitude' => -79.38,
                'occurrence_time' => Carbon::now(),
            ],
        ]);
        $mock->shouldReceive('lastFetchWasPartial')->once()->andReturnFalse();
    });

    $this->artisan('police:fetch-calls')
        ->expectsOutputToContain('Found 1 calls in the feed')
        ->expectsOutputToContain('Successfully updated police calls')
        ->assertExitCode(0);

    $this->assertDatabaseHas('police_calls', [
        'object_id' => 123,
        'is_active' => true,
    ]);
});

test('it updates existing records on re-fetch', function () {
    PoliceCall::factory()->create([
        'object_id' => 123,
        'call_type' => 'OLD TYPE',
        'is_active' => true,
    ]);

    $this->mock(TorontoPoliceFeedService::class, function (MockInterface $mock) {
        $mock->shouldReceive('fetch')->once()->andReturn([
            [
                'object_id' => 123,
                'call_type_code' => 'BREPR',
                'call_type' => 'NEW TYPE',
                'division' => 'D42',
                'cross_streets' => 'BAY ST - YORK ST',
                'latitude' => 43.65,
                'longitude' => -79.38,
                'occurrence_time' => Carbon::now(),
            ],
        ]);
        $mock->shouldReceive('lastFetchWasPartial')->once()->andReturnFalse();
    });

    $this->artisan('police:fetch-calls')->assertExitCode(0);

    $this->assertDatabaseHas('police_calls', [
        'object_id' => 123,
        'call_type' => 'NEW TYPE',
        'is_active' => true,
    ]);
    expect(PoliceCall::count())->toBe(1);
});

test('it deactivates calls no longer in the feed', function () {
    PoliceCall::factory()->create([
        'object_id' => 111,
        'is_active' => true,
    ]);

    $this->mock(TorontoPoliceFeedService::class, function (MockInterface $mock) {
        $mock->shouldReceive('fetch')->once()->andReturn([
            [
                'object_id' => 222,
                'call_type_code' => 'BREPR',
                'call_type' => 'BREAK & ENTER IN PROGRESS',
                'division' => 'D42',
                'cross_streets' => 'BAY ST - YORK ST',
                'latitude' => 43.65,
                'longitude' => -79.38,
                'occurrence_time' => Carbon::now(),
            ],
        ]);
        $mock->shouldReceive('lastFetchWasPartial')->once()->andReturnFalse();
    });

    $this->artisan('police:fetch-calls')
        ->expectsOutputToContain('Deactivated 1 stale calls')
        ->assertExitCode(0);

    $this->assertDatabaseHas('police_calls', [
        'object_id' => 111,
        'is_active' => false,
    ]);
    $this->assertDatabaseHas('police_calls', [
        'object_id' => 222,
        'is_active' => true,
    ]);
});

test('it preserves existing data when feed is empty and empty feeds are not allowed', function () {
    PoliceCall::factory()->create(['is_active' => true]);

    $this->mock(TorontoPoliceFeedService::class, function (MockInterface $mock) {
        $mock->shouldReceive('fetch')->once()->andThrow(new RuntimeException('Toronto Police feed returned zero records'));
    });

    $this->artisan('police:fetch-calls')
        ->expectsOutputToContain('Failed to fetch police calls: Toronto Police feed returned zero records')
        ->assertExitCode(1);

    expect(PoliceCall::where('is_active', true)->count())->toBe(1);
});

test('active scope returns only active records', function () {
    PoliceCall::factory()->count(3)->create(['is_active' => true]);
    PoliceCall::factory()->count(2)->create(['is_active' => false]);

    expect(PoliceCall::active()->count())->toBe(3);
    expect(PoliceCall::count())->toBe(5);
});

test('it returns failure on service exception', function () {
    $this->mock(TorontoPoliceFeedService::class, function (MockInterface $mock) {
        $mock->shouldReceive('fetch')->once()->andThrow(new RuntimeException('API Down'));
    });

    $this->artisan('police:fetch-calls')
        ->expectsOutputToContain('Failed to fetch police calls: API Down')
        ->assertExitCode(1);
});

test('it dispatches alert created events for new calls', function () {
    Event::fake([AlertCreated::class]);

    $this->mock(TorontoPoliceFeedService::class, function (MockInterface $mock) {
        $mock->shouldReceive('fetch')->once()->andReturn([
            [
                'object_id' => 123,
                'call_type_code' => 'BREPR',
                'call_type' => 'BREAK & ENTER IN PROGRESS',
                'division' => 'D42',
                'cross_streets' => 'BAY ST - YORK ST',
                'latitude' => 43.65,
                'longitude' => -79.38,
                'occurrence_time' => Carbon::parse('2026-02-10T09:30:00Z'),
            ],
        ]);
        $mock->shouldReceive('lastFetchWasPartial')->once()->andReturnFalse();
    });

    $this->artisan('police:fetch-calls')->assertExitCode(0);

    Event::assertDispatched(AlertCreated::class, function (AlertCreated $event): bool {
        return $event->alert->alertId === 'police:123'
            && $event->alert->source === 'police'
            && $event->alert->severity === 'major';
    });
});

test('it skips deactivation when the police feed fetch is partial', function () {
    PoliceCall::factory()->create([
        'object_id' => 111,
        'is_active' => true,
    ]);

    $this->mock(TorontoPoliceFeedService::class, function (MockInterface $mock) {
        $mock->shouldReceive('fetch')->once()->andReturn([
            [
                'object_id' => 222,
                'call_type_code' => 'BREPR',
                'call_type' => 'BREAK & ENTER IN PROGRESS',
                'division' => 'D42',
                'cross_streets' => 'BAY ST - YORK ST',
                'latitude' => 43.65,
                'longitude' => -79.38,
                'occurrence_time' => Carbon::now(),
            ],
        ]);
        $mock->shouldReceive('lastFetchWasPartial')->once()->andReturnTrue();
    });

    $this->artisan('police:fetch-calls')->assertExitCode(0);

    $this->assertDatabaseHas('police_calls', [
        'object_id' => 111,
        'is_active' => true,
    ]);

    $this->assertDatabaseHas('police_calls', [
        'object_id' => 222,
        'is_active' => true,
    ]);
});
