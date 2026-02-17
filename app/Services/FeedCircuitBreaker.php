<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class FeedCircuitBreaker
{
    public function throwIfOpen(string $feedName): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        try {
            $threshold = $this->threshold();
            $failures = Cache::get($this->cacheKey($feedName));

            if (is_int($failures) && $failures >= $threshold) {
                Log::warning('Feed circuit breaker is open; skipping fetch attempt', [
                    'feed' => $feedName,
                    'failures' => $failures,
                    'threshold' => $threshold,
                    'ttl_seconds' => $this->ttlSeconds(),
                ]);

                throw new RuntimeException("Circuit breaker open for feed '{$feedName}'");
            }
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('Failed to evaluate feed circuit breaker state; proceeding without breaker', [
                'exception' => $exception,
                'feed' => $feedName,
            ]);
        }
    }

    public function recordSuccess(string $feedName): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        try {
            Cache::forget($this->cacheKey($feedName));
        } catch (Throwable $exception) {
            Log::warning('Failed to clear feed circuit breaker state', [
                'exception' => $exception,
                'feed' => $feedName,
            ]);
        }
    }

    public function recordFailure(string $feedName, Throwable $exception): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        try {
            $key = $this->cacheKey($feedName);
            $ttl = $this->ttlSeconds();
            $threshold = $this->threshold();

            $current = Cache::get($key);
            $failures = (is_int($current) ? $current : 0) + 1;

            Cache::put($key, $failures, $ttl);

            if ($failures >= $threshold) {
                Log::warning('Feed circuit breaker opened', [
                    'feed' => $feedName,
                    'failures' => $failures,
                    'threshold' => $threshold,
                    'ttl_seconds' => $ttl,
                    'exception_class' => $exception::class,
                ]);
            }
        } catch (Throwable $cacheException) {
            Log::warning('Failed to update feed circuit breaker state', [
                'exception' => $cacheException,
                'feed' => $feedName,
                'original_exception_class' => $exception::class,
            ]);
        }
    }

    private function isEnabled(): bool
    {
        return (bool) config('feeds.circuit_breaker.enabled', true);
    }

    private function threshold(): int
    {
        $value = (int) config('feeds.circuit_breaker.threshold', 5);

        return max(1, $value);
    }

    private function ttlSeconds(): int
    {
        $value = (int) config('feeds.circuit_breaker.ttl_seconds', 300);

        return max(1, $value);
    }

    private function cacheKey(string $feedName): string
    {
        return 'feeds:circuit_breaker:'.trim($feedName);
    }
}
