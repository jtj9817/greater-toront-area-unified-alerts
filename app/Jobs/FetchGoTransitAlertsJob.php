<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

class FetchGoTransitAlertsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('fetch-go-transit-alerts'))
                ->releaseAfter(30)
                ->expireAfter(10 * 60),
        ];
    }

    public function handle(): void
    {
        $exitCode = Artisan::call('go-transit:fetch-alerts');

        if ($exitCode !== 0) {
            throw new RuntimeException("go-transit:fetch-alerts failed with exit code {$exitCode}");
        }
    }

    public function uniqueId(): string
    {
        return 'fetch-go-transit-alerts';
    }
}
