<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

class SchedulerReportCommand extends Command
{
    protected $signature = 'scheduler:report {--startup : Mark this as a startup report}';

    protected $description = 'Log scheduler configuration and the current schedule:list output';

    public function handle(): int
    {
        $context = [
            'startup' => (bool) $this->option('startup'),
            'app_env' => config('app.env'),
            'app_debug' => (bool) config('app.debug'),
            'timezone' => config('app.timezone'),
            'pid' => getmypid(),
        ];

        Log::info('Scheduler report starting', $context);

        try {
            $exitCode = Artisan::call('schedule:list', ['--no-interaction' => true]);
            $output = trim($this->stripAnsi(Artisan::output()));

            if ($output !== '') {
                foreach (preg_split('/\\r?\\n/', $output) as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }

                    Log::info('Scheduler schedule:list', ['line' => $line] + $context);
                }
            } else {
                Log::info('Scheduler schedule:list produced no output', $context);
            }

            if ($exitCode !== self::SUCCESS) {
                Log::warning('Scheduler schedule:list exited non-zero', ['exit_code' => $exitCode] + $context);
            }
        } catch (Throwable $e) {
            Log::error('Scheduler report failed', ['error' => $e->getMessage()] + $context);

            return self::FAILURE;
        }

        Log::info('Scheduler report finished', $context);

        return self::SUCCESS;
    }

    private function stripAnsi(string $value): string
    {
        return preg_replace('/\\e\\[[0-9;]*m/', '', $value) ?? $value;
    }
}
