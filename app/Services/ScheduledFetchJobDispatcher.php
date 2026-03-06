<?php

namespace App\Services;

use App\Jobs\FetchFireIncidentsJob;
use App\Jobs\FetchGoTransitAlertsJob;
use App\Jobs\FetchPoliceCallsJob;
use App\Jobs\FetchTransitAlertsJob;
use BackedEnum;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\QueueingDispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;
use UnitEnum;

class ScheduledFetchJobDispatcher
{
    public function __construct(
        private readonly QueueingDispatcher $dispatcher,
        private readonly CacheRepository $cache,
    ) {}

    public function dispatchFireIncidents(): bool
    {
        return $this->dispatchUnique('fire:fetch-incidents', new FetchFireIncidentsJob);
    }

    public function dispatchPoliceCalls(): bool
    {
        return $this->dispatchUnique('police:fetch-calls', new FetchPoliceCallsJob);
    }

    public function dispatchTransitAlerts(): bool
    {
        return $this->dispatchUnique('transit:fetch-alerts', new FetchTransitAlertsJob);
    }

    public function dispatchGoTransitAlerts(): bool
    {
        return $this->dispatchUnique('go-transit:fetch-alerts', new FetchGoTransitAlertsJob);
    }

    private function dispatchUnique(string $source, ShouldQueue&ShouldBeUnique $job): bool
    {
        if ($this->hasOutstandingDatabaseQueueRow($job)) {
            Log::info('Scheduled fetch job skipped', [
                'source' => $source,
                'job_class' => $job::class,
                'reason' => 'database_queue_row_exists',
                'unique_lock_key' => UniqueLock::getKey($job),
            ]);

            return false;
        }

        $uniqueLock = new UniqueLock($this->cache);

        if (! $uniqueLock->acquire($job)) {
            Log::info('Scheduled fetch job skipped', [
                'source' => $source,
                'job_class' => $job::class,
                'reason' => 'unique_lock_held',
                'unique_lock_key' => UniqueLock::getKey($job),
            ]);

            return false;
        }

        if ($this->hasOutstandingDatabaseQueueRow($job)) {
            $uniqueLock->release($job);

            Log::info('Scheduled fetch job skipped', [
                'source' => $source,
                'job_class' => $job::class,
                'reason' => 'database_queue_row_exists_after_lock',
                'unique_lock_key' => UniqueLock::getKey($job),
            ]);

            return false;
        }

        try {
            $this->dispatcher->dispatchToQueue($job);
        } catch (Throwable $exception) {
            $uniqueLock->release($job);

            Log::error('Scheduled fetch job enqueue failed', [
                'source' => $source,
                'job_class' => $job::class,
                'unique_lock_key' => UniqueLock::getKey($job),
                'exception' => $exception,
            ]);

            throw $exception;
        }

        Log::info('Scheduled fetch job enqueued', [
            'source' => $source,
            'job_class' => $job::class,
            'unique_lock_key' => UniqueLock::getKey($job),
        ]);

        return true;
    }

    private function hasOutstandingDatabaseQueueRow(ShouldQueue $job): bool
    {
        $defaultConnection = (string) config('queue.default');
        $driver = config("queue.connections.{$defaultConnection}.driver");

        if ($driver !== 'database') {
            return false;
        }

        $databaseConnection = config("queue.connections.{$defaultConnection}.connection")
            ?? config('database.default');
        $table = config("queue.connections.{$defaultConnection}.table", 'jobs');
        $queue = $job->queue ?? config("queue.connections.{$defaultConnection}.queue", 'default');
        $queueName = match (true) {
            is_string($queue) && $queue !== '' => $queue,
            $queue instanceof BackedEnum => (string) $queue->value,
            $queue instanceof UnitEnum => $queue->name,
            default => 'default',
        };

        if (! is_string($databaseConnection) || $databaseConnection === '') {
            return false;
        }

        if (! is_string($table) || $table === '') {
            return false;
        }

        if (! Schema::connection($databaseConnection)->hasTable($table)) {
            return false;
        }

        $escapedDisplayName = str_replace('\\', '\\\\', $job::class);
        $needle = "\"displayName\":\"{$escapedDisplayName}\"";

        return DB::connection($databaseConnection)
            ->table($table)
            ->where('queue', $queueName)
            ->where('payload', 'like', "%{$needle}%")
            ->exists();
    }
}
