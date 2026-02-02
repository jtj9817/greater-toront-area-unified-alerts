<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SchedulerRunAndLogCommand extends Command
{
    public const LAST_TICK_AT_KEY = 'scheduler:last_tick_at';

    public const LAST_TICK_EXIT_CODE_KEY = 'scheduler:last_tick_exit_code';

    public const LAST_TICK_DURATION_MS_KEY = 'scheduler:last_tick_duration_ms';

    protected $signature = 'scheduler:run-and-log';

    protected $description = 'Run schedule:run and log output into Laravel logs (also stores a heartbeat for health checks)';

    public function handle(): int
    {
        $startedAt = microtime(true);
        $context = [
            'pid' => getmypid(),
        ];

        Log::info('Scheduler tick starting', $context);

        try {
            $exitCode = Artisan::call('schedule:run', ['--no-interaction' => true]);
            $output = trim($this->stripAnsi(Artisan::output()));
        } catch (Throwable $e) {
            Log::error('Scheduler tick threw an exception', ['error' => $e->getMessage()] + $context);
            $this->writeHeartbeat(self::FAILURE, (int) ((microtime(true) - $startedAt) * 1000));

            return self::FAILURE;
        }

        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);
        $this->writeHeartbeat((int) $exitCode, $durationMs);

        if ($output !== '') {
            foreach (preg_split('/\\r?\\n/', $output) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                Log::info('Scheduler tick output', ['line' => $line] + $context);
            }
        }

        $level = $exitCode === self::SUCCESS ? 'info' : 'error';
        Log::$level('Scheduler tick finished', ['exit_code' => $exitCode, 'duration_ms' => $durationMs] + $context);

        return $exitCode === self::SUCCESS ? self::SUCCESS : self::FAILURE;
    }

    private function writeHeartbeat(int $exitCode, int $durationMs): void
    {
        $ttl = now()->addDay();

        Cache::put(self::LAST_TICK_AT_KEY, now()->timestamp, $ttl);
        Cache::put(self::LAST_TICK_EXIT_CODE_KEY, $exitCode, $ttl);
        Cache::put(self::LAST_TICK_DURATION_MS_KEY, $durationMs, $ttl);
    }

    private function stripAnsi(string $value): string
    {
        return preg_replace('/\\e\\[[0-9;]*m/', '', $value) ?? $value;
    }
}
