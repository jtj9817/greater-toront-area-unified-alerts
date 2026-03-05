<?php

namespace App\Providers;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class QueueExecutionDebugServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->debugEnabled()) {
            return;
        }

        $matchers = $this->matchers();

        $this->app['events']->listen(JobProcessing::class, function (JobProcessing $event) use ($matchers): void {
            if (! $this->shouldLog($event->job, $matchers)) {
                return;
            }

            Log::channel('queue_execution')->info('Queue job processing', $this->jobContext($event->job, $event->connectionName));
        });

        $this->app['events']->listen(JobProcessed::class, function (JobProcessed $event) use ($matchers): void {
            if (! $this->shouldLog($event->job, $matchers)) {
                return;
            }

            Log::channel('queue_execution')->info('Queue job processed', $this->jobContext($event->job, $event->connectionName));
        });

        $this->app['events']->listen(JobFailed::class, function (JobFailed $event) use ($matchers): void {
            if (! $this->shouldLog($event->job, $matchers)) {
                return;
            }

            Log::channel('queue_execution')->error('Queue job failed', [
                ...$this->jobContext($event->job, $event->connectionName),
                'exception' => [
                    'class' => $event->exception::class,
                    'message' => $event->exception->getMessage(),
                ],
            ]);
        });
    }

    private function debugEnabled(): bool
    {
        $configured = env('QUEUE_DEBUG_EXECUTION');

        if ($configured !== null) {
            return filter_var($configured, FILTER_VALIDATE_BOOL);
        }

        return $this->app->environment('local');
    }

    /**
     * @return array<int, string>
     */
    private function matchers(): array
    {
        $raw = trim((string) env('QUEUE_DEBUG_EXECUTION_MATCH', ''));

        if ($raw === '') {
            return [
                \App\Jobs\FetchFireIncidentsJob::class,
                \App\Jobs\FetchPoliceCallsJob::class,
                \App\Jobs\FetchTransitAlertsJob::class,
                \App\Jobs\FetchGoTransitAlertsJob::class,
                \App\Jobs\GenerateDailyDigestJob::class,
            ];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $value): bool => $value !== ''));
    }

    /**
     * @param  array<int, string>  $matchers
     */
    private function shouldLog(Job $job, array $matchers): bool
    {
        return $this->matches($job->resolveName(), $matchers);
    }

    /**
     * @param  array<int, string>  $matchers
     */
    private function matches(string $displayName, array $matchers): bool
    {
        if ($displayName === '') {
            return false;
        }

        if ($matchers === ['*']) {
            return true;
        }

        foreach ($matchers as $matcher) {
            if ($matcher === '') {
                continue;
            }

            if ($displayName === $matcher) {
                return true;
            }

            if (str_contains($displayName, $matcher)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function jobContext(Job $job, string $connectionName): array
    {
        return [
            'job_id' => $job->getJobId(),
            'uuid' => $job->uuid(),
            'display_name' => $job->resolveName(),
            'queued_job_class' => $job->resolveQueuedJobClass(),
            'connection' => $connectionName,
            'job_connection' => $job->getConnectionName(),
            'queue' => $job->getQueue(),
            'attempt' => $job->attempts(),
            'worker' => [
                'pid' => getmypid(),
                'hostname' => gethostname() ?: null,
                'argv' => $_SERVER['argv'] ?? null,
            ],
        ];
    }
}
