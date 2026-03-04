<?php

use App\Events\AlertCreated;
use App\Models\PoliceCall;
use App\Services\Notifications\NotificationAlert;
use App\Services\Notifications\NotificationAlertFactory;
use App\Services\Notifications\NotificationSeverity;
use App\Services\TorontoPoliceFeedService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

test('it rethrows QueryException so the command fails and can be retried', function () {
    Log::spy();

    $this->mock(TorontoPoliceFeedService::class, function (MockInterface $mock) {
        $mock->shouldReceive('fetch')->once()->andReturn([
            [
                'object_id' => null,
                'call_type_code' => 'TEST',
                'call_type' => 'TEST CALL',
                'division' => 'D11',
                'cross_streets' => 'A ST - B ST',
                'latitude' => 43.65,
                'longitude' => -79.38,
                'occurrence_time' => Carbon::parse('2026-02-17 00:00:00', 'UTC'),
            ],
        ]);

        $mock->shouldReceive('lastFetchWasPartial')->once()->andReturn(true);
    });

    Event::fake([AlertCreated::class]);

    $this->artisan('police:fetch-calls')->assertExitCode(1);

    Log::shouldHaveReceived('error')
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'FetchPoliceCallsCommand failed'
                && ($context['exception'] ?? null) instanceof QueryException;
        })
        ->once();
});

test('it skips a single record when a non-db exception occurs and continues processing', function () {
    Log::spy();
    Event::fake([AlertCreated::class]);

    $this->mock(TorontoPoliceFeedService::class, function (MockInterface $mock) {
        $mock->shouldReceive('fetch')->once()->andReturn([
            [
                'object_id' => 1,
                'call_type_code' => 'TEST',
                'call_type' => 'TEST CALL',
                'division' => 'D11',
                'cross_streets' => 'A ST - B ST',
                'latitude' => 43.65,
                'longitude' => -79.38,
                'occurrence_time' => Carbon::parse('2026-02-17 00:00:00', 'UTC'),
            ],
            [
                'object_id' => 2,
                'call_type_code' => 'TEST',
                'call_type' => 'TEST CALL',
                'division' => 'D11',
                'cross_streets' => 'A ST - B ST',
                'latitude' => 43.65,
                'longitude' => -79.38,
                'occurrence_time' => Carbon::parse('2026-02-17 00:01:00', 'UTC'),
            ],
        ]);

        $mock->shouldReceive('lastFetchWasPartial')->once()->andReturn(false);
    });

    $this->mock(NotificationAlertFactory::class, function (MockInterface $mock) {
        $mock->shouldReceive('fromPoliceCall')
            ->once()
            ->andThrow(new RuntimeException('factory failed'));

        $mock->shouldReceive('fromPoliceCall')
            ->once()
            ->andReturn(new NotificationAlert(
                alertId: 'police:2',
                source: 'police',
                severity: NotificationSeverity::MINOR,
                summary: 'Test',
                occurredAt: CarbonImmutable::now(),
            ));
    });

    $this->artisan('police:fetch-calls')->assertExitCode(0);

    expect(PoliceCall::query()->count())->toBe(2);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Skipping police call record due to persistence failure' && isset($context['exception']))
        ->once();

    Event::assertDispatchedTimes(AlertCreated::class, 1);
});

test('it preserves existing calls when pagination is partial and the feed returns empty', function () {
    Event::fake([AlertCreated::class]);

    PoliceCall::factory()->create([
        'object_id' => 123,
        'is_active' => true,
    ]);

    $this->mock(TorontoPoliceFeedService::class, function (MockInterface $mock) {
        $mock->shouldReceive('fetch')->once()->andReturn([]);
        $mock->shouldReceive('lastFetchWasPartial')->once()->andReturn(true);
    });

    $this->artisan('police:fetch-calls')->assertExitCode(0);

    expect(PoliceCall::where('object_id', 123)->value('is_active'))->toBeTrue();
    Event::assertNotDispatched(AlertCreated::class);
});

test('it does not dispatch an alert when an existing active call remains active', function () {
    Event::fake([AlertCreated::class]);

    PoliceCall::factory()->create([
        'object_id' => 123,
        'is_active' => true,
        'call_type_code' => 'TEST',
        'call_type' => 'TEST CALL',
        'division' => 'D11',
        'cross_streets' => 'A ST - B ST',
        'latitude' => 43.65,
        'longitude' => -79.38,
        'occurrence_time' => Carbon::parse('2026-02-17 00:00:00', 'UTC'),
    ]);

    $this->mock(TorontoPoliceFeedService::class, function (MockInterface $mock) {
        $mock->shouldReceive('fetch')->once()->andReturn([
            [
                'object_id' => 123,
                'call_type_code' => 'TEST',
                'call_type' => 'TEST CALL',
                'division' => 'D11',
                'cross_streets' => 'A ST - B ST',
                'latitude' => 43.65,
                'longitude' => -79.38,
                'occurrence_time' => Carbon::parse('2026-02-17 00:00:00', 'UTC'),
            ],
        ]);

        $mock->shouldReceive('lastFetchWasPartial')->once()->andReturn(false);
    });

    $this->artisan('police:fetch-calls')->assertExitCode(0);

    Event::assertNotDispatched(AlertCreated::class);
});

test('it detects OBJECTID sequence reset, clears stale rows, and fires AlertCreated for repopulated calls', function () {
    Event::fake([AlertCreated::class]);
    Log::spy();

    // Simulate pre-reset DB state: high object_id values
    PoliceCall::factory()->create(['object_id' => 2000, 'is_active' => true]);
    PoliceCall::factory()->create(['object_id' => 1800, 'is_active' => false]);

    // Feed returns low object_ids typical of a sequence reset (1/2000 = 0.0005 < 0.1 threshold)
    $this->mock(TorontoPoliceFeedService::class, function (MockInterface $mock) {
        $mock->shouldReceive('fetch')->once()->andReturn([
            [
                'object_id' => 1,
                'call_type_code' => 'BREPR',
                'call_type' => 'BREAK & ENTER IN PROGRESS',
                'division' => 'D42',
                'cross_streets' => 'BAY ST - YORK ST',
                'latitude' => 43.65,
                'longitude' => -79.38,
                'occurrence_time' => Carbon::parse('2026-03-04 09:30:00', 'UTC'),
            ],
        ]);
        $mock->shouldReceive('lastFetchWasPartial')->once()->andReturnFalse();
    });

    $this->artisan('police:fetch-calls')->assertExitCode(0);

    // Stale rows cleared; only the single post-reset call remains
    expect(PoliceCall::count())->toBe(1);
    expect(PoliceCall::value('object_id'))->toBe(1);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'OBJECTID sequence reset detected'))
        ->once();

    // AlertCreated must fire because the row was freshly inserted (wasRecentlyCreated = true)
    Event::assertDispatched(AlertCreated::class, fn (AlertCreated $event): bool => $event->alert->alertId === 'police:1'
    );
});

test('it rolls back reset clear when reseed hits QueryException and preserves pre-reset rows', function () {
    Event::fake([AlertCreated::class]);
    Log::spy();

    PoliceCall::factory()->create(['object_id' => 2000, 'is_active' => true]);
    PoliceCall::factory()->create(['object_id' => 1800, 'is_active' => false]);

    $this->mock(TorontoPoliceFeedService::class, function (MockInterface $mock) {
        $mock->shouldReceive('fetch')->once()->andReturn([
            [
                'object_id' => 1,
                'call_type_code' => 'BREPR',
                'call_type' => 'BREAK & ENTER IN PROGRESS',
                'division' => 'D42',
                'cross_streets' => 'BAY ST - YORK ST',
                'latitude' => 43.65,
                'longitude' => -79.38,
                'occurrence_time' => Carbon::parse('2026-03-04 09:30:00', 'UTC'),
            ],
            [
                'object_id' => null,
                'call_type_code' => 'BREPR',
                'call_type' => 'BREAK & ENTER IN PROGRESS',
                'division' => 'D42',
                'cross_streets' => 'BAY ST - YORK ST',
                'latitude' => 43.65,
                'longitude' => -79.38,
                'occurrence_time' => Carbon::parse('2026-03-04 09:31:00', 'UTC'),
            ],
        ]);
        $mock->shouldReceive('lastFetchWasPartial')->once()->andReturnFalse();
    });

    $this->artisan('police:fetch-calls')->assertExitCode(1);

    expect(PoliceCall::query()->count())->toBe(2);
    expect(PoliceCall::where('object_id', 1)->exists())->toBeFalse();
    $this->assertDatabaseHas('police_calls', ['object_id' => 1800]);
    $this->assertDatabaseHas('police_calls', ['object_id' => 2000]);
    Event::assertNotDispatched(AlertCreated::class);
});

test('it does not trigger reset detection when feed OBJECTIDs are consistent with DB history', function () {
    Event::fake([AlertCreated::class]);

    // Seed a single inactive call with object_id 100
    PoliceCall::factory()->create(['object_id' => 100, 'is_active' => false]);

    // Feed returns object_id 101 — ratio 101/100 = 1.01, well above the 0.1 threshold
    $this->mock(TorontoPoliceFeedService::class, function (MockInterface $mock) {
        $mock->shouldReceive('fetch')->once()->andReturn([
            [
                'object_id' => 101,
                'call_type_code' => 'BREPR',
                'call_type' => 'BREAK & ENTER IN PROGRESS',
                'division' => 'D42',
                'cross_streets' => 'BAY ST - YORK ST',
                'latitude' => 43.65,
                'longitude' => -79.38,
                'occurrence_time' => Carbon::parse('2026-03-04 09:30:00', 'UTC'),
            ],
        ]);
        $mock->shouldReceive('lastFetchWasPartial')->once()->andReturnFalse();
    });

    $this->artisan('police:fetch-calls')->assertExitCode(0);

    // Both rows must be present — old one deactivated (already was), new one active
    expect(PoliceCall::count())->toBe(2);
    expect(PoliceCall::where('object_id', 100)->value('is_active'))->toBeFalse();
    expect(PoliceCall::where('object_id', 101)->value('is_active'))->toBeTrue();
});

test('it handles duplicate object ids without creating duplicate records or notifications', function () {
    Event::fake([AlertCreated::class]);

    $this->mock(TorontoPoliceFeedService::class, function (MockInterface $mock) {
        $mock->shouldReceive('fetch')->once()->andReturn([
            [
                'object_id' => 123,
                'call_type_code' => 'TEST',
                'call_type' => 'TEST CALL',
                'division' => 'D11',
                'cross_streets' => 'A ST - B ST',
                'latitude' => 43.65,
                'longitude' => -79.38,
                'occurrence_time' => Carbon::parse('2026-02-17 00:00:00', 'UTC'),
            ],
            [
                'object_id' => 123,
                'call_type_code' => 'TEST',
                'call_type' => 'TEST CALL',
                'division' => 'D11',
                'cross_streets' => 'A ST - B ST',
                'latitude' => 43.65,
                'longitude' => -79.38,
                'occurrence_time' => Carbon::parse('2026-02-17 00:00:00', 'UTC'),
            ],
        ]);

        $mock->shouldReceive('lastFetchWasPartial')->once()->andReturn(false);
    });

    $this->artisan('police:fetch-calls')->assertExitCode(0);

    expect(PoliceCall::query()->count())->toBe(1);
    Event::assertDispatchedTimes(AlertCreated::class, 1);
});
