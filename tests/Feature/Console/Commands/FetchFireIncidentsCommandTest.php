<?php

use App\Console\Commands\FetchFireIncidentsCommand;
use App\Models\FireIncident;
use App\Services\Notifications\NotificationAlert;
use App\Services\Notifications\NotificationAlertFactory;
use App\Services\Notifications\NotificationSeverity;
use App\Services\SceneIntel\SceneIntelProcessor;
use App\Services\TorontoFireFeedService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

it('continues processing incidents when scene intel processor fails', function () {
    // 1. Mock the feed service
    $this->mock(TorontoFireFeedService::class, function (MockInterface $mock) {
        $mock->shouldReceive('fetch')->once()->andReturn([
            'updated_at' => '2023-01-01 12:00:00',
            'events' => [
                [
                    'event_num' => 'E001',
                    'event_type' => 'Fire',
                    'prime_street' => 'Street 1',
                    'cross_streets' => 'Cross 1',
                    'dispatch_time' => '2023-01-01T12:00:00',
                    'alarm_level' => 1,
                    'beat' => 'B1',
                    'units_dispatched' => 'U1',
                ],
                [
                    'event_num' => 'E002',
                    'event_type' => 'Medical',
                    'prime_street' => 'Street 2',
                    'cross_streets' => 'Cross 2',
                    'dispatch_time' => '2023-01-01T12:05:00',
                    'alarm_level' => 0,
                    'beat' => 'B2',
                    'units_dispatched' => 'U2',
                ],
            ],
        ]);
    });

    // 2. Mock the scene intel processor
    $this->mock(SceneIntelProcessor::class, function (MockInterface $mock) {
        // First call throws exception
        $mock->shouldReceive('processIncidentUpdate')
            ->withArgs(fn ($incident) => $incident->event_num === 'E001')
            ->once()
            ->andThrow(new Exception('Intel generation failed'));

        // Second call succeeds
        $mock->shouldReceive('processIncidentUpdate')
            ->withArgs(fn ($incident) => $incident->event_num === 'E002')
            ->once();
    });

    // We also need to mock NotificationAlertFactory since it's used in the command
    $this->mock(NotificationAlertFactory::class, function (MockInterface $mock) {
        $mock->shouldReceive('fromFireIncident')->andReturn(new NotificationAlert(
            alertId: 'test',
            source: 'fire',
            severity: NotificationSeverity::MINOR,
            summary: 'Test',
            occurredAt: CarbonImmutable::now()
        ));
    });

    // 3. Run the command
    // We assert exit code 0 because we expect the command to catch the exception and continue
    $this->artisan(FetchFireIncidentsCommand::class)
        ->assertExitCode(0);

    // 4. Verify both incidents exist in DB
    expect(FireIncident::where('event_num', 'E001')->exists())->toBeTrue();
    expect(FireIncident::where('event_num', 'E002')->exists())->toBeTrue();
});

it('optimizes the query to select only necessary columns', function () {
    // Create an existing incident
    FireIncident::factory()->create([
        'event_num' => 'E001',
        'is_active' => true,
    ]);

    $this->mock(TorontoFireFeedService::class, function (MockInterface $mock) {
        $mock->shouldReceive('fetch')->once()->andReturn([
            'updated_at' => '2023-01-01 12:00:00',
            'events' => [
                [
                    'event_num' => 'E001',
                    'event_type' => 'Fire',
                    'prime_street' => 'Street 1',
                    'cross_streets' => 'Cross 1',
                    'dispatch_time' => '2023-01-01T12:00:00',
                    'alarm_level' => 1,
                    'beat' => 'B1',
                    'units_dispatched' => 'U1',
                ],
            ],
        ]);
    });

    $this->mock(SceneIntelProcessor::class, function (MockInterface $mock) {
        $mock->shouldReceive('processIncidentUpdate');
    });

    $this->mock(NotificationAlertFactory::class, function (MockInterface $mock) {
        $mock->shouldReceive('fromFireIncident')->andReturn(new NotificationAlert(
            alertId: 'test',
            source: 'fire',
            severity: NotificationSeverity::MINOR,
            summary: 'Test',
            occurredAt: CarbonImmutable::now()
        ));
    });

    DB::enableQueryLog();

    $this->artisan(FetchFireIncidentsCommand::class);

    $queries = DB::getQueryLog();
    $foundOptimizedQuery = false;

    foreach ($queries as $query) {
        // We look for the query that fetches existing incidents by event_num
        // The expected columns are: id, event_num, alarm_level, units_dispatched, is_active
        if (str_contains($query['query'], 'select "id", "event_num", "alarm_level", "units_dispatched", "is_active" from "fire_incidents"')) {
            $foundOptimizedQuery = true;
            break;
        }
        if (str_contains($query['query'], 'select `id`, `event_num`, `alarm_level`, `units_dispatched`, `is_active` from `fire_incidents`')) {
            $foundOptimizedQuery = true;
            break;
        }
    }

    expect($foundOptimizedQuery)->toBeTrue('Optimized query with specific columns not found.');
});

it('logs a warning when scene intel failure rate exceeds 50%', function () {
    Log::spy();

    FireIncident::factory()->create(['event_num' => 'E001', 'is_active' => true]);
    FireIncident::factory()->create(['event_num' => 'E002', 'is_active' => true]);
    FireIncident::factory()->create(['event_num' => 'E003', 'is_active' => true]);

    $this->mock(TorontoFireFeedService::class, function (MockInterface $mock) {
        $mock->shouldReceive('fetch')->once()->andReturn([
            'updated_at' => '2026-02-17 00:00:00',
            'events' => [
                [
                    'event_num' => 'E001',
                    'event_type' => 'Fire',
                    'prime_street' => 'Street 1',
                    'cross_streets' => 'Cross 1',
                    'dispatch_time' => '2026-02-17T00:00:00',
                    'alarm_level' => 1,
                    'beat' => 'B1',
                    'units_dispatched' => 'U1',
                ],
                [
                    'event_num' => 'E002',
                    'event_type' => 'Fire',
                    'prime_street' => 'Street 2',
                    'cross_streets' => 'Cross 2',
                    'dispatch_time' => '2026-02-17T00:01:00',
                    'alarm_level' => 1,
                    'beat' => 'B1',
                    'units_dispatched' => 'U1',
                ],
                [
                    'event_num' => 'E003',
                    'event_type' => 'Fire',
                    'prime_street' => 'Street 3',
                    'cross_streets' => 'Cross 3',
                    'dispatch_time' => '2026-02-17T00:02:00',
                    'alarm_level' => 1,
                    'beat' => 'B1',
                    'units_dispatched' => 'U1',
                ],
            ],
        ]);
    });

    $this->mock(SceneIntelProcessor::class, function (MockInterface $mock) {
        $mock->shouldReceive('processIncidentUpdate')
            ->withArgs(fn ($incident, $previousData) => $incident->event_num === 'E001' && is_array($previousData))
            ->once()
            ->andThrow(new Exception('intel failed'));

        $mock->shouldReceive('processIncidentUpdate')
            ->withArgs(fn ($incident, $previousData) => $incident->event_num === 'E002' && is_array($previousData))
            ->once()
            ->andThrow(new Exception('intel failed'));

        $mock->shouldReceive('processIncidentUpdate')
            ->withArgs(fn ($incident, $previousData) => $incident->event_num === 'E003' && is_array($previousData))
            ->once();
    });

    $this->artisan(FetchFireIncidentsCommand::class)->assertExitCode(0);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Scene intel failure rate exceeded threshold'
            && ($context['attempts'] ?? null) === 3
            && ($context['failures'] ?? null) === 2)
        ->once();
});
