<?php

namespace App\Jobs;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class FetchYrtAlertsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('fetch-yrt-alerts'))
                ->releaseAfter(30)
                ->expireAfter(10 * 60),
        ];
    }

    public function handle(): void
    {
        $exitCode = Artisan::call('yrt:fetch-alerts');

        if ($exitCode !== 0) {
            throw new RuntimeException("yrt:fetch-alerts failed with exit code {$exitCode}");
        }
    }

    public function uniqueId(): string
    {
        return 'fetch-yrt-alerts';
    }

    public function uniqueVia(): CacheRepository
    {
        return Cache::store((string) config('queue.unique_lock_store', 'file'));
    }
}
