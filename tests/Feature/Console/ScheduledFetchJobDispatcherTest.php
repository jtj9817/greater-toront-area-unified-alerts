<?php

use App\Jobs\FetchDrtAlertsJob;
use App\Jobs\FetchFireIncidentsJob;
use App\Jobs\FetchGoTransitAlertsJob;
use App\Jobs\FetchMiwayAlertsJob;
use App\Jobs\FetchPoliceCallsJob;
use App\Jobs\FetchTransitAlertsJob;
use App\Jobs\FetchYrtAlertsJob;
use App\Services\ScheduledFetchJobDispatcher;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\QueueingDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'queue.default' => 'database',
        'queue.unique_lock_store' => 'array',
    ]);

    app()->forgetInstance('cache');
    app()->forgetInstance('cache.store');

    Cache::flush();
    Cache::store((string) config('queue.unique_lock_store'))->flush();
});

afterEach(function () {
    Cache::flush();
    Cache::store((string) config('queue.unique_lock_store'))->flush();

    app()->forgetInstance('cache');
    app()->forgetInstance('cache.store');
});

test('dispatch methods enqueue one scheduled fetch job per source', function () {
    $dispatcher = app(ScheduledFetchJobDispatcher::class);

    expect($dispatcher->dispatchFireIncidents())->toBeTrue();
    expect($dispatcher->dispatchPoliceCalls())->toBeTrue();
    expect($dispatcher->dispatchTransitAlerts())->toBeTrue();
    expect($dispatcher->dispatchGoTransitAlerts())->toBeTrue();
    expect($dispatcher->dispatchMiwayAlerts())->toBeTrue();
    expect($dispatcher->dispatchYrtAlerts())->toBeTrue();
    expect($dispatcher->dispatchDrtAlerts())->toBeTrue();

    expect(queuedJobCount(FetchFireIncidentsJob::class))->toBe(1);
    expect(queuedJobCount(FetchPoliceCallsJob::class))->toBe(1);
    expect(queuedJobCount(FetchTransitAlertsJob::class))->toBe(1);
    expect(queuedJobCount(FetchGoTransitAlertsJob::class))->toBe(1);
    expect(queuedJobCount(FetchMiwayAlertsJob::class))->toBe(1);
    expect(queuedJobCount(FetchYrtAlertsJob::class))->toBe(1);
    expect(queuedJobCount(FetchDrtAlertsJob::class))->toBe(1);
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
                && ($context['reason'] ?? null) === 'outstanding_queue_row_exists';
        })
        ->once();
});

test('dispatch yrt alerts skips duplicate enqueue when an equivalent job is already queued', function () {
    Log::spy();

    $dispatcher = app(ScheduledFetchJobDispatcher::class);

    expect($dispatcher->dispatchYrtAlerts())->toBeTrue();
    expect($dispatcher->dispatchYrtAlerts())->toBeFalse();

    expect(queuedJobCount(FetchYrtAlertsJob::class))->toBe(1);

    Log::shouldHaveReceived('info')
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'Scheduled fetch job skipped'
                && ($context['job_class'] ?? null) === FetchYrtAlertsJob::class
                && ($context['reason'] ?? null) === 'outstanding_queue_row_exists';
        })
        ->once();
});

test('dispatch drt alerts skips duplicate enqueue when an equivalent job is already queued', function () {
    Log::spy();

    $dispatcher = app(ScheduledFetchJobDispatcher::class);

    expect($dispatcher->dispatchDrtAlerts())->toBeTrue();
    expect($dispatcher->dispatchDrtAlerts())->toBeFalse();

    expect(queuedJobCount(FetchDrtAlertsJob::class))->toBe(1);

    Log::shouldHaveReceived('info')
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'Scheduled fetch job skipped'
                && ($context['job_class'] ?? null) === FetchDrtAlertsJob::class
                && ($context['reason'] ?? null) === 'outstanding_queue_row_exists';
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

test('dispatch skips when an equivalent unique lock is already held without a queue row', function () {
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

test('dispatch skips when an equivalent queue row exists even if lock is held', function () {
    Log::spy();

    app(QueueingDispatcher::class)->dispatchToQueue(new FetchFireIncidentsJob);

    $lock = new UniqueLock(app('cache.store'));
    expect($lock->acquire(new FetchFireIncidentsJob))->toBeTrue();

    $dispatcher = app(ScheduledFetchJobDispatcher::class);

    expect($dispatcher->dispatchFireIncidents())->toBeFalse();
    expect(queuedJobCount(FetchFireIncidentsJob::class))->toBe(1);

    Log::shouldHaveReceived('info')
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'Scheduled fetch job skipped'
                && ($context['job_class'] ?? null) === FetchFireIncidentsJob::class
                && ($context['reason'] ?? null) === 'outstanding_queue_row_exists';
        })
        ->once();

    $lock->release(new FetchFireIncidentsJob);
});

test('dispatch methods use the configured unique lock store instead of the default cache store', function () {
    config([
        'cache.default' => 'database',
        'queue.default' => 'database',
        'queue.unique_lock_store' => 'array',
    ]);

    app()->forgetInstance('cache');
    app()->forgetInstance('cache.store');

    Cache::store('array')->flush();

    expect(DB::table('cache_locks')->count())->toBe(0);

    $dispatcher = app(ScheduledFetchJobDispatcher::class);

    expect($dispatcher->dispatchFireIncidents())->toBeTrue();
    expect($dispatcher->dispatchPoliceCalls())->toBeTrue();
    expect($dispatcher->dispatchTransitAlerts())->toBeTrue();
    expect($dispatcher->dispatchGoTransitAlerts())->toBeTrue();
    expect($dispatcher->dispatchMiwayAlerts())->toBeTrue();
    expect($dispatcher->dispatchYrtAlerts())->toBeTrue();
    expect($dispatcher->dispatchDrtAlerts())->toBeTrue();

    expect(DB::table('cache_locks')->count())->toBe(0);
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

test('post-lock queue check failure releases the unique lock so later retries can enqueue', function () {
    $mockDispatcher = Mockery::mock(QueueingDispatcher::class);
    $mockDispatcher->shouldNotReceive('dispatchToQueue');

    $dispatcher = new class($mockDispatcher, app('cache.store')) extends ScheduledFetchJobDispatcher
    {
        private int $queueRowCheckCount = 0;

        protected function hasOutstandingDatabaseQueueRow(ShouldQueue $job): bool
        {
            $this->queueRowCheckCount++;

            if ($this->queueRowCheckCount === 2) {
                throw new RuntimeException('queue read failed');
            }

            return false;
        }
    };

    expect(fn () => $dispatcher->dispatchFireIncidents())
        ->toThrow(RuntimeException::class, 'queue read failed');

    $lock = new UniqueLock(app('cache.store'));
    expect($lock->acquire(new FetchFireIncidentsJob))->toBeTrue();
    $lock->release(new FetchFireIncidentsJob);
});

function queuedJobCount(string $jobClass): int
{
    return DB::table('jobs')
        ->pluck('payload')
        ->filter(fn (mixed $payload): bool => queuedPayloadDisplayName($payload) === $jobClass)
        ->count();
}

function deleteQueuedJobs(string $jobClass): void
{
    $jobIds = DB::table('jobs')
        ->select(['id', 'payload'])
        ->get()
        ->filter(fn (object $row): bool => queuedPayloadDisplayName($row->payload) === $jobClass)
        ->pluck('id')
        ->all();

    if ($jobIds === []) {
        return;
    }

    DB::table('jobs')->whereIn('id', $jobIds)->delete();
}

function queuedPayloadDisplayName(mixed $payload): ?string
{
    if (! is_string($payload) || trim($payload) === '') {
        return null;
    }

    try {
        $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }

    $displayName = $decoded['displayName'] ?? null;

    if (! is_string($displayName) || $displayName === '') {
        return null;
    }

    return $displayName;
}

// ============================================================================
// Phase 4: ScheduledFetchJobDispatcher Outstanding-Queue Branch Coverage
// ============================================================================

// --- Task 2: Post-lock recheck path ---

test('post-lock recheck finds outstanding row after lock acquired and releases lock', function () {
    Log::spy();

    $mockDispatcher = Mockery::mock(QueueingDispatcher::class);
    $mockDispatcher->shouldNotReceive('dispatchToQueue');

    $dispatcher = new class($mockDispatcher, app('cache.store')) extends ScheduledFetchJobDispatcher
    {
        private int $checkCount = 0;

        protected function hasOutstandingDatabaseQueueRow(ShouldQueue $job): bool
        {
            $this->checkCount++;

            // First check (pre-lock): false → proceeds to acquire lock
            // Second check (post-lock): true → releases lock and returns false
            return $this->checkCount === 2;
        }
    };

    expect($dispatcher->dispatchFireIncidents())->toBeFalse();

    // Lock should be released — subsequent acquire succeeds
    $lock = new UniqueLock(app('cache.store'));
    expect($lock->acquire(new FetchFireIncidentsJob))->toBeTrue();
    $lock->release(new FetchFireIncidentsJob);

    Log::shouldHaveReceived('info')
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'Scheduled fetch job skipped'
                && ($context['reason'] ?? null) === 'outstanding_queue_row_exists_after_lock';
        })
        ->once();
});

// --- Task 1: Early-return guard rails ---

test('hasOutstanding returns false when queue driver is not database', function () {
    config(['queue.default' => 'sync']);

    $mockDispatcher = Mockery::mock(QueueingDispatcher::class);
    $testable = new TestableScheduledFetchJobDispatcher($mockDispatcher, app('cache.store'));

    expect($testable->hasOutstanding(new FetchFireIncidentsJob))->toBeFalse();
});

test('hasOutstanding returns false when database connection name is empty', function () {
    config(['queue.connections.database.connection' => '']);

    $mockDispatcher = Mockery::mock(QueueingDispatcher::class);
    $testable = new TestableScheduledFetchJobDispatcher($mockDispatcher, app('cache.store'));

    expect($testable->hasOutstanding(new FetchFireIncidentsJob))->toBeFalse();
});

test('hasOutstanding returns false when queue table name is empty', function () {
    config(['queue.connections.database.table' => '']);

    $mockDispatcher = Mockery::mock(QueueingDispatcher::class);
    $testable = new TestableScheduledFetchJobDispatcher($mockDispatcher, app('cache.store'));

    expect($testable->hasOutstanding(new FetchFireIncidentsJob))->toBeFalse();
});

test('hasOutstanding returns false when queue table does not exist', function () {
    config(['queue.connections.database.table' => 'jobs_missing']);

    $mockDispatcher = Mockery::mock(QueueingDispatcher::class);
    $testable = new TestableScheduledFetchJobDispatcher($mockDispatcher, app('cache.store'));

    expect($testable->hasOutstanding(new FetchFireIncidentsJob))->toBeFalse();
});

// --- Task 3: Queue name resolution branches ---

test('hasOutstanding resolves string queue name and finds matching row', function () {
    // Dispatch a real job to the database queue with a string queue name
    $dispatched = (new TestQueueNameJob)->onQueue('expected-name');
    app(QueueingDispatcher::class)->dispatchToQueue($dispatched);

    $testable = new TestableScheduledFetchJobDispatcher(
        dispatcher: Mockery::mock(QueueingDispatcher::class),
        cache: app('cache.store'),
    );

    $checkJob = new TestQueueNameJob;
    $checkJob->onQueue('expected-name');

    expect($testable->hasOutstanding($checkJob))->toBeTrue();
});

test('hasOutstanding finds matching row for namespaced queued job payload', function () {
    app(QueueingDispatcher::class)->dispatchToQueue(new FetchFireIncidentsJob);

    $testable = new TestableScheduledFetchJobDispatcher(
        dispatcher: Mockery::mock(QueueingDispatcher::class),
        cache: app('cache.store'),
    );

    expect($testable->hasOutstanding(new FetchFireIncidentsJob))->toBeTrue();
});

test('hasOutstanding resolves BackedEnum queue name and finds matching row', function () {
    // Dispatch to the string value that the BackedEnum resolves to
    $dispatched = (new TestQueueNameJob)->onQueue('expected-name');
    app(QueueingDispatcher::class)->dispatchToQueue($dispatched);

    $testable = new TestableScheduledFetchJobDispatcher(
        dispatcher: Mockery::mock(QueueingDispatcher::class),
        cache: app('cache.store'),
    );

    // Check using a BackedEnum queue — resolves to 'expected-name'
    $checkJob = new TestQueueNameJob;
    $checkJob->queue = TestBackedQueue::Expected;

    expect($testable->hasOutstanding($checkJob))->toBeTrue();
});

test('hasOutstanding resolves UnitEnum queue name and finds matching row', function () {
    // Dispatch to the enum case name that the UnitEnum resolves to
    $dispatched = (new TestQueueNameJob)->onQueue('Expected');
    app(QueueingDispatcher::class)->dispatchToQueue($dispatched);

    $testable = new TestableScheduledFetchJobDispatcher(
        dispatcher: Mockery::mock(QueueingDispatcher::class),
        cache: app('cache.store'),
    );

    // Check using a UnitEnum queue — resolves to 'Expected' (case name)
    $checkJob = new TestQueueNameJob;
    $checkJob->queue = TestUnitQueue::Expected;

    expect($testable->hasOutstanding($checkJob))->toBeTrue();
});

test('hasOutstanding resolves empty-string queue to default and finds matching row', function () {
    // Dispatch to the 'default' queue (what the default match arm resolves to)
    $dispatched = (new TestQueueNameJob)->onQueue('default');
    app(QueueingDispatcher::class)->dispatchToQueue($dispatched);

    $testable = new TestableScheduledFetchJobDispatcher(
        dispatcher: Mockery::mock(QueueingDispatcher::class),
        cache: app('cache.store'),
    );

    // Empty-string queue hits the default match arm → 'default'
    $checkJob = new TestQueueNameJob;
    $checkJob->queue = '';

    expect($testable->hasOutstanding($checkJob))->toBeTrue();
});

test('hasOutstanding returns false when queue name does not match dispatched row', function () {
    // Dispatch to 'other' queue
    $dispatched = (new TestQueueNameJob)->onQueue('other');
    app(QueueingDispatcher::class)->dispatchToQueue($dispatched);

    $testable = new TestableScheduledFetchJobDispatcher(
        dispatcher: Mockery::mock(QueueingDispatcher::class),
        cache: app('cache.store'),
    );

    // Check with a different queue name
    $checkJob = new TestQueueNameJob;
    $checkJob->onQueue('expected-name');

    expect($testable->hasOutstanding($checkJob))->toBeFalse();
});

// --- Phase 4 Test-Only Helpers ---

enum TestBackedQueue: string
{
    case Expected = 'expected-name';
    case Other = 'other';
}

enum TestUnitQueue
{
    case Expected;
    case Other;
}

class TestQueueNameJob implements ShouldQueue
{
    use \Illuminate\Foundation\Queue\Queueable;
}

class TestableScheduledFetchJobDispatcher extends ScheduledFetchJobDispatcher
{
    public function hasOutstanding(ShouldQueue $job): bool
    {
        return $this->hasOutstandingDatabaseQueueRow($job);
    }
}
