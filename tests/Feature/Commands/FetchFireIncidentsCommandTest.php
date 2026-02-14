<?php

use App\Enums\IncidentUpdateType;
use App\Models\FireIncident;
use App\Models\IncidentUpdate;
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

test('it generates synthetic intel updates for changed incidents and deactivations', function () {
    FireIncident::factory()->create([
        'event_num' => 'F26030001',
        'event_type' => 'FIRE',
        'alarm_level' => 1,
        'units_dispatched' => 'P101, R301',
        'is_active' => true,
    ]);

    FireIncident::factory()->create([
        'event_num' => 'F26039999',
        'event_type' => 'FIRE',
        'is_active' => true,
    ]);

    $feedData = [
        'updated_at' => '2026-02-13 08:30:00',
        'events' => [
            [
                'event_num' => 'F26030001',
                'event_type' => 'FIRE',
                'prime_street' => 'KING ST W',
                'cross_streets' => 'SPADINA AVE / BRANT ST',
                'dispatch_time' => '2026-02-13T08:00:00',
                'alarm_level' => 2,
                'beat' => '101',
                'units_dispatched' => 'P101, R201',
            ],
        ],
    ];

    $this->mock(TorontoFireFeedService::class, function (MockInterface $mock) use ($feedData) {
        $mock->shouldReceive('fetch')->once()->andReturn($feedData);
    });

    $this->artisan('fire:fetch-incidents')->assertExitCode(0);

    $changedIncidentUpdates = IncidentUpdate::query()
        ->forIncident('F26030001')
        ->orderBy('id')
        ->get();

    expect($changedIncidentUpdates)->toHaveCount(3);
    expect($changedIncidentUpdates[0]->update_type)->toBe(IncidentUpdateType::ALARM_CHANGE);
    expect($changedIncidentUpdates[0]->content)->toBe('Alarm level increased from 1 to 2');
    expect($changedIncidentUpdates[1]->update_type)->toBe(IncidentUpdateType::RESOURCE_STATUS);
    expect($changedIncidentUpdates[1]->content)->toBe('Unit R201 dispatched');
    expect($changedIncidentUpdates[2]->update_type)->toBe(IncidentUpdateType::RESOURCE_STATUS);
    expect($changedIncidentUpdates[2]->content)->toBe('Unit R301 cleared');

    $deactivatedIncidentUpdates = IncidentUpdate::query()
        ->forIncident('F26039999')
        ->get();

    expect($deactivatedIncidentUpdates)->toHaveCount(1);
    expect($deactivatedIncidentUpdates[0]->update_type)->toBe(IncidentUpdateType::PHASE_CHANGE);
    expect($deactivatedIncidentUpdates[0]->content)->toBe('Incident marked as resolved');
});

test('it does not duplicate closure intel updates for the same transition during deactivation', function () {
    $incident = FireIncident::factory()->create([
        'event_num' => 'F26037777',
        'event_type' => 'FIRE',
        'is_active' => true,
    ]);

    IncidentUpdate::factory()->create([
        'event_num' => $incident->event_num,
        'update_type' => IncidentUpdateType::PHASE_CHANGE,
        'content' => 'Incident marked as resolved',
        'metadata' => [
            'previous_phase' => 'active',
            'new_phase' => 'resolved',
        ],
        'source' => 'synthetic',
    ]);

    $feedData = [
        'updated_at' => '2026-02-13 08:45:00',
        'events' => [],
    ];

    $this->mock(TorontoFireFeedService::class, function (MockInterface $mock) use ($feedData) {
        $mock->shouldReceive('fetch')->once()->andReturn($feedData);
    });

    $this->artisan('fire:fetch-incidents')->assertExitCode(0);

    $closureUpdates = IncidentUpdate::query()
        ->forIncident($incident->event_num)
        ->where('update_type', IncidentUpdateType::PHASE_CHANGE)
        ->where('content', 'Incident marked as resolved')
        ->count();

    expect($closureUpdates)->toBe(1);
});

test('it records a new closure intel update after an incident is reactivated', function () {
    $incident = FireIncident::factory()->create([
        'event_num' => 'F26037778',
        'event_type' => 'FIRE',
        'is_active' => false,
    ]);

    IncidentUpdate::factory()->create([
        'event_num' => $incident->event_num,
        'update_type' => IncidentUpdateType::PHASE_CHANGE,
        'content' => 'Incident marked as resolved',
        'metadata' => [
            'previous_phase' => 'active',
            'new_phase' => 'resolved',
        ],
        'source' => 'synthetic',
    ]);

    $reactivationFeedData = [
        'updated_at' => '2026-02-13 08:50:00',
        'events' => [
            [
                'event_num' => $incident->event_num,
                'event_type' => 'FIRE',
                'prime_street' => 'QUEEN ST W',
                'cross_streets' => 'SPADINA AVE / AUGUSTA AVE',
                'dispatch_time' => '2026-02-13T08:40:00',
                'alarm_level' => 1,
                'beat' => '143',
                'units_dispatched' => 'P143',
            ],
        ],
    ];

    $deactivationFeedData = [
        'updated_at' => '2026-02-13 08:55:00',
        'events' => [],
    ];

    $this->mock(TorontoFireFeedService::class, function (MockInterface $mock) use ($reactivationFeedData, $deactivationFeedData) {
        $mock->shouldReceive('fetch')->twice()->andReturn(
            $reactivationFeedData,
            $deactivationFeedData,
        );
    });

    $this->artisan('fire:fetch-incidents')->assertExitCode(0);
    $this->artisan('fire:fetch-incidents')->assertExitCode(0);

    $closureUpdates = IncidentUpdate::query()
        ->forIncident($incident->event_num)
        ->where('update_type', IncidentUpdateType::PHASE_CHANGE)
        ->where('content', 'Incident marked as resolved')
        ->orderBy('id')
        ->get();

    expect($closureUpdates)->toHaveCount(2);
    expect(
        IncidentUpdate::query()
            ->forIncident($incident->event_num)
            ->where('update_type', IncidentUpdateType::PHASE_CHANGE)
            ->where('content', 'Incident marked as active')
            ->count()
    )->toBe(1);
    expect($closureUpdates->last()->metadata)->toBe([
        'previous_phase' => 'active',
        'new_phase' => 'resolved',
    ]);
});
