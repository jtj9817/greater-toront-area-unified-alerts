<?php

use App\Jobs\FetchFireIncidentsJob;
use App\Jobs\FetchGoTransitAlertsJob;
use App\Jobs\FetchPoliceCallsJob;
use App\Jobs\FetchTransitAlertsJob;
use App\Services\ScheduledFetchJobDispatcher;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\QueueingDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'queue.default' => 'database',
    ]);

    Cache::flush();
});

afterEach(function () {
    Cache::flush();
});

test('dispatch methods enqueue one scheduled fetch job per source', function () {
    $dispatcher = app(ScheduledFetchJobDispatcher::class);

    expect($dispatcher->dispatchFireIncidents())->toBeTrue();
    expect($dispatcher->dispatchPoliceCalls())->toBeTrue();
    expect($dispatcher->dispatchTransitAlerts())->toBeTrue();
    expect($dispatcher->dispatchGoTransitAlerts())->toBeTrue();

    expect(queuedJobCount(FetchFireIncidentsJob::class))->toBe(1);
    expect(queuedJobCount(FetchPoliceCallsJob::class))->toBe(1);
    expect(queuedJobCount(FetchTransitAlertsJob::class))->toBe(1);
    expect(queuedJobCount(FetchGoTransitAlertsJob::class))->toBe(1);
});

test('dispatch skips duplicate enqueue when an equivalent job is already queued', function () {
    Log::spy();

    $dispatcher = app(ScheduledFetchJobDispatcher::class);

    expect($dispatcher->dispatchFireIncidents())->toBeTrue();
    expect($dispatcher->dispatchFireIncidents())->toBeFalse();

    expect(queuedJobCount(FetchFireIncidentsJob::class))->toBe(1);

    Log::shouldHaveReceived('info')
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'Scheduled fetch job skipped'
                && ($context['job_class'] ?? null) === FetchFireIncidentsJob::class
                && ($context['reason'] ?? null) === 'database_queue_row_exists';
        })
        ->once();
});

test('dispatch re-enqueues after the prior job has completed and lock is released', function () {
    $dispatcher = app(ScheduledFetchJobDispatcher::class);

    expect($dispatcher->dispatchFireIncidents())->toBeTrue();
    expect(queuedJobCount(FetchFireIncidentsJob::class))->toBe(1);

    deleteQueuedJobs(FetchFireIncidentsJob::class);
    (new UniqueLock(app('cache.store')))->release(new FetchFireIncidentsJob);

    expect($dispatcher->dispatchFireIncidents())->toBeTrue();
    expect(queuedJobCount(FetchFireIncidentsJob::class))->toBe(1);
});

test('dispatch skips when unique lock is already held', function () {
    Log::spy();

    $lock = new UniqueLock(app('cache.store'));
    expect($lock->acquire(new FetchFireIncidentsJob))->toBeTrue();

    $dispatcher = app(ScheduledFetchJobDispatcher::class);

    expect($dispatcher->dispatchFireIncidents())->toBeFalse();
    expect(queuedJobCount(FetchFireIncidentsJob::class))->toBe(0);

    Log::shouldHaveReceived('info')
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'Scheduled fetch job skipped'
                && ($context['job_class'] ?? null) === FetchFireIncidentsJob::class
                && ($context['reason'] ?? null) === 'unique_lock_held';
        })
        ->once();

    $lock->release(new FetchFireIncidentsJob);
});

test('dispatch failure releases the unique lock so later retries can enqueue', function () {
    $mockDispatcher = Mockery::mock(QueueingDispatcher::class);
    $mockDispatcher->shouldReceive('dispatchToQueue')
        ->once()
        ->with(Mockery::type(FetchFireIncidentsJob::class))
        ->andThrow(new RuntimeException('dispatch failed'));

    $dispatcher = new ScheduledFetchJobDispatcher(
        dispatcher: $mockDispatcher,
        cache: app('cache.store'),
    );

    expect(fn () => $dispatcher->dispatchFireIncidents())
        ->toThrow(RuntimeException::class, 'dispatch failed');

    $lock = new UniqueLock(app('cache.store'));
    expect($lock->acquire(new FetchFireIncidentsJob))->toBeTrue();
    $lock->release(new FetchFireIncidentsJob);
});

function queuedJobCount(string $jobClass): int
{
    $escapedDisplayName = str_replace('\\', '\\\\', $jobClass);
    $needle = "\"displayName\":\"{$escapedDisplayName}\"";

    return DB::table('jobs')
        ->where('payload', 'like', "%{$needle}%")
        ->count();
}

function deleteQueuedJobs(string $jobClass): void
{
    $escapedDisplayName = str_replace('\\', '\\\\', $jobClass);
    $needle = "\"displayName\":\"{$escapedDisplayName}\"";

    DB::table('jobs')
        ->where('payload', 'like', "%{$needle}%")
        ->delete();
}
