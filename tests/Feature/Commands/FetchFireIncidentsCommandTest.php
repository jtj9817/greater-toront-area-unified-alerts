<?php

use App\Models\FireIncident;
use App\Services\TorontoFireFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

test('it syncs fire incidents from the service', function () {
    $feedData = [
        'updated_at' => '2026-01-31 13:45:01',
        'events' => [
            [
                'event_num' => 'F26015952',
                'event_type' => 'Rescue - Elevator',
                'prime_street' => 'WILSON AVE, NY',
                'cross_streets' => 'AGATE RD / JULIAN RD',
                'dispatch_time' => '2026-01-31T13:15:32',
                'alarm_level' => 0,
                'beat' => '144',
                'units_dispatched' => 'P144, S143',
            ],
            [
                'event_num' => 'F26015953',
                'event_type' => 'FIRE',
                'prime_street' => 'MAIN ST',
                'cross_streets' => null,
                'dispatch_time' => '2026-01-31T13:20:00',
                'alarm_level' => 1,
                'beat' => '100',
                'units_dispatched' => 'P100',
            ],
        ],
    ];

    $this->mock(TorontoFireFeedService::class, function (MockInterface $mock) use ($feedData) {
        $mock->shouldReceive('fetch')->once()->andReturn($feedData);
    });

    $this->artisan('fire:fetch-incidents')
        ->expectsOutput('Fetching Toronto Fire active incidents...')
        ->expectsOutputToContain('Done. 2 active incidents synced, 0 marked inactive.')
        ->assertExitCode(0);

    expect(FireIncident::count())->toBe(2);
    expect(FireIncident::where('event_num', 'F26015952')->first()->is_active)->toBeTrue();
    expect(FireIncident::where('event_num', 'F26015953')->first()->is_active)->toBeTrue();
});

test('it deactivates incidents no longer in the feed', function () {
    // Existing active incident that should be deactivated
    FireIncident::factory()->create([
        'event_num' => 'OLD123',
        'is_active' => true,
    ]);

    // Existing active incident that should stay active
    FireIncident::factory()->create([
        'event_num' => 'STAY456',
        'is_active' => true,
    ]);

    $feedData = [
        'updated_at' => '2026-01-31 13:45:01',
        'events' => [
            [
                'event_num' => 'STAY456',
                'event_type' => 'FIRE',
                'prime_street' => 'MAIN ST',
                'cross_streets' => null,
                'dispatch_time' => '2026-01-31T13:20:00',
                'alarm_level' => 1,
                'beat' => '100',
                'units_dispatched' => 'P100',
            ],
            [
                'event_num' => 'NEW789',
                'event_type' => 'MEDICAL',
                'prime_street' => 'NEW ST',
                'cross_streets' => null,
                'dispatch_time' => '2026-01-31T13:30:00',
                'alarm_level' => 0,
                'beat' => '200',
                'units_dispatched' => 'P200',
            ],
        ],
    ];

    $this->mock(TorontoFireFeedService::class, function (MockInterface $mock) use ($feedData) {
        $mock->shouldReceive('fetch')->once()->andReturn($feedData);
    });

    $this->artisan('fire:fetch-incidents')
        ->expectsOutputToContain('Done. 2 active incidents synced, 1 marked inactive.')
        ->assertExitCode(0);

    expect(FireIncident::where('event_num', 'OLD123')->first()->is_active)->toBeFalse();
    expect(FireIncident::where('event_num', 'STAY456')->first()->is_active)->toBeTrue();
    expect(FireIncident::where('event_num', 'NEW789')->first()->is_active)->toBeTrue();
});

test('it handles service failures gracefully', function () {
    $this->mock(TorontoFireFeedService::class, function (MockInterface $mock) {
        $mock->shouldReceive('fetch')->once()->andThrow(new RuntimeException('Service unavailable'));
    });

    $this->artisan('fire:fetch-incidents')
        ->expectsOutput('Feed fetch failed: Service unavailable')
        ->assertExitCode(1);
});