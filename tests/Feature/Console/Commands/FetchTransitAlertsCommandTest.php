<?php

use App\Events\AlertCreated;
use App\Models\TransitAlert;
use App\Services\TtcAlertsFeedService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

test('it skips alerts with invalid external ids (including whitespace)', function () {
    Event::fake([AlertCreated::class]);

    $this->mock(TtcAlertsFeedService::class, function (MockInterface $mock) {
        $mock->shouldReceive('fetch')->once()->andReturn([
            'updated_at' => Carbon::parse('2026-02-17 00:00:00', 'UTC'),
            'alerts' => [
                [
                    'external_id' => null,
                    'source_feed' => 'ttc_live',
                    'title' => 'Ignored',
                    'effect' => 'DELAY',
                ],
                [
                    'external_id' => '   ',
                    'source_feed' => 'ttc_live',
                    'title' => 'Ignored',
                    'effect' => 'DELAY',
                ],
                [
                    'external_id' => ['not-a-string'],
                    'source_feed' => 'ttc_live',
                    'title' => 'Ignored',
                    'effect' => 'DELAY',
                ],
                [
                    'external_id' => 'api:valid',
                    'source_feed' => 'ttc_live',
                    'title' => 'Valid',
                    'effect' => 'DELAY',
                ],
            ],
        ]);
    });

    $this->artisan('transit:fetch-alerts')->assertExitCode(0);

    expect(TransitAlert::query()->count())->toBe(1);
    expect(TransitAlert::query()->value('external_id'))->toBe('api:valid');
});

test('it rethrows QueryException so the command fails and can be retried', function () {
    Log::spy();
    Event::fake([AlertCreated::class]);

    $this->mock(TtcAlertsFeedService::class, function (MockInterface $mock) {
        $mock->shouldReceive('fetch')->once()->andReturn([
            'updated_at' => Carbon::parse('2026-02-17 00:00:00', 'UTC'),
            'alerts' => [
                [
                    'external_id' => 'api:bad',
                    'source_feed' => 'ttc_live',
                    'title' => null,
                    'effect' => 'DELAY',
                ],
            ],
        ]);
    });

    $this->artisan('transit:fetch-alerts')->assertExitCode(1);

    Log::shouldHaveReceived('error')
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'FetchTransitAlertsCommand failed'
                && ($context['exception'] ?? null) instanceof QueryException;
        })
        ->once();
});

test('it dispatches accessibility notifications based on status transitions and ignores null effect', function () {
    Event::fake([AlertCreated::class]);

    TransitAlert::factory()->create([
        'external_id' => 'api:acc-1',
        'source_feed' => 'ttc_accessibility',
        'title' => 'Elevator',
        'effect' => 'OUT_OF_SERVICE',
        'is_active' => true,
    ]);

    $this->mock(TtcAlertsFeedService::class, function (MockInterface $mock) {
        $mock->shouldReceive('fetch')->once()->andReturn([
            'updated_at' => Carbon::parse('2026-02-17 00:00:00', 'UTC'),
            'alerts' => [
                // OUT -> IN should dispatch
                [
                    'external_id' => 'api:acc-1',
                    'source_feed' => 'ttc_accessibility',
                    'title' => 'Elevator',
                    'effect' => 'IN_SERVICE',
                ],
                // Effect null should be treated as no-op
                [
                    'external_id' => 'api:acc-null',
                    'source_feed' => 'ttc_accessibility',
                    'title' => 'Elevator',
                    'effect' => null,
                ],
                // New OUT_OF_SERVICE should dispatch
                [
                    'external_id' => 'api:acc-2',
                    'source_feed' => 'ttc_accessibility',
                    'title' => 'Escalator',
                    'effect' => 'OUT_OF_SERVICE',
                ],
                // New IN_SERVICE should not dispatch
                [
                    'external_id' => 'api:acc-3',
                    'source_feed' => 'ttc_accessibility',
                    'title' => 'Ramp',
                    'effect' => 'IN_SERVICE',
                ],
            ],
        ]);
    });

    $this->artisan('transit:fetch-alerts')->assertExitCode(0);

    Event::assertDispatchedTimes(AlertCreated::class, 2);
});

test('it dispatches accessibility notifications when transitioning from in service to out of service', function () {
    Event::fake([AlertCreated::class]);

    TransitAlert::factory()->create([
        'external_id' => 'api:acc-4',
        'source_feed' => 'ttc_accessibility',
        'title' => 'Elevator',
        'effect' => 'IN_SERVICE',
        'is_active' => true,
    ]);

    $this->mock(TtcAlertsFeedService::class, function (MockInterface $mock) {
        $mock->shouldReceive('fetch')->once()->andReturn([
            'updated_at' => Carbon::parse('2026-02-17 00:00:00', 'UTC'),
            'alerts' => [
                [
                    'external_id' => 'api:acc-4',
                    'source_feed' => 'ttc_accessibility',
                    'title' => 'Elevator',
                    'effect' => 'OUT_OF_SERVICE',
                ],
            ],
        ]);
    });

    $this->artisan('transit:fetch-alerts')->assertExitCode(0);

    Event::assertDispatchedTimes(AlertCreated::class, 1);
});
