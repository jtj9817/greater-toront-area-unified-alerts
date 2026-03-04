<?php

namespace App\Providers;

use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Throwable;

class QueueEnqueueDebugServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->debugEnabled()) {
            return;
        }

        $matchers = $this->matchers();

        $this->app['events']->listen(JobQueued::class, function (JobQueued $event) use ($matchers): void {
            try {
                $payload = $event->payload();
            } catch (Throwable $e) {
                Log::channel('queue_enqueues')->warning('Queue enqueue debug: failed to decode payload', [
                    'error' => $e->getMessage(),
                    'connection' => $event->connectionName,
                    'queue' => $event->queue,
                    'job_id' => $event->id,
                ]);

                return;
            }

            $displayName = (string) ($payload['displayName'] ?? '');

            if (! $this->matches($displayName, $matchers)) {
                return;
            }

            Log::channel('queue_enqueues')->info('Queue job enqueued', [
                'job_id' => $event->id,
                'display_name' => $displayName,
                'connection' => $event->connectionName,
                'queue' => $event->queue,
                'delay' => $event->delay,
                'enqueuer' => [
                    'pid' => getmypid(),
                    'hostname' => gethostname() ?: null,
                    'argv' => $_SERVER['argv'] ?? null,
                ],
                'payload_meta' => $this->payloadMeta($payload),
                'stack' => $this->includeStack()
                    ? $this->compactStack(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), limit: 18)
                    : null,
            ]);
        });
    }

    private function debugEnabled(): bool
    {
        return filter_var(env('QUEUE_DEBUG_ENQUEUES', false), FILTER_VALIDATE_BOOL);
    }

    /**
     * @return array<int, string>
     */
    private function matchers(): array
    {
        $raw = trim((string) env('QUEUE_DEBUG_ENQUEUES_MATCH', ''));

        if ($raw === '') {
            return [
                \App\Jobs\FetchFireIncidentsJob::class,
                \App\Jobs\FetchPoliceCallsJob::class,
                \App\Jobs\FetchTransitAlertsJob::class,
                \App\Jobs\FetchGoTransitAlertsJob::class,
                \App\Jobs\GenerateDailyDigestJob::class,
                \App\Jobs\FanOutAlertNotificationsJob::class,
                \App\Jobs\DispatchAlertNotificationChunkJob::class,
            ];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $value): bool => $value !== ''));
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

    private function includeStack(): bool
    {
        return filter_var(env('QUEUE_DEBUG_ENQUEUES_STACK', false), FILTER_VALIDATE_BOOL);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function payloadMeta(array $payload): array
    {
        // Keep this small; the payload can contain serialized job data.
        return array_filter([
            'uuid' => $payload['uuid'] ?? null,
            'attempts' => $payload['attempts'] ?? null,
            'maxTries' => $payload['maxTries'] ?? null,
            'timeout' => $payload['timeout'] ?? null,
            'retryUntil' => $payload['retryUntil'] ?? null,
        ], static fn ($value): bool => $value !== null);
    }

    /**
     * @param  array<int, array<string, mixed>>  $frames
     * @return array<int, array<string, mixed>>
     */
    private function compactStack(array $frames, int $limit): array
    {
        $out = [];

        foreach ($frames as $frame) {
            if (count($out) >= $limit) {
                break;
            }

            $file = $frame['file'] ?? null;
            $line = $frame['line'] ?? null;
            $function = $frame['function'] ?? null;
            $class = $frame['class'] ?? null;

            if (! is_string($file) || ! is_int($line)) {
                continue;
            }

            $out[] = array_filter([
                'file' => $file,
                'line' => $line,
                'call' => is_string($function) ? ($class ? "{$class}::{$function}" : $function) : null,
            ], static fn ($value): bool => $value !== null);
        }

        return $out;
    }
}
