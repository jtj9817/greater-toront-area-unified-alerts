<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SchedulerStatusCommand extends Command
{
    protected $signature = 'scheduler:status {--max-age=5 : Max age (minutes) for the last scheduler tick}';

    protected $description = 'Check scheduler heartbeat and log failures for easier debugging';

    public function handle(): int
    {
        $maxAgeMinutes = (int) $this->option('max-age');
        $lastTickAt = Cache::get(SchedulerRunAndLogCommand::LAST_TICK_AT_KEY);

        $context = [
            'max_age_minutes' => $maxAgeMinutes,
            'pid' => getmypid(),
        ];

        if ($lastTickAt === null) {
            Log::error('Scheduler heartbeat missing (no scheduler ticks recorded yet)', $context);
            $this->error('Scheduler heartbeat missing');

            return self::FAILURE;
        }

        $lastTickAt = CarbonImmutable::createFromTimestamp((int) $lastTickAt);
        $ageMinutes = $lastTickAt->diffInMinutes(now());

        $context['last_tick_at'] = $lastTickAt->toIso8601String();
        $context['age_minutes'] = $ageMinutes;
        $context['last_exit_code'] = Cache::get(SchedulerRunAndLogCommand::LAST_TICK_EXIT_CODE_KEY);
        $context['last_duration_ms'] = Cache::get(SchedulerRunAndLogCommand::LAST_TICK_DURATION_MS_KEY);

        if ($ageMinutes > $maxAgeMinutes) {
            Log::error('Scheduler heartbeat stale', $context);
            $this->error("Scheduler heartbeat stale ({$ageMinutes}m old)");

            return self::FAILURE;
        }

        $this->info("Scheduler OK (last tick {$ageMinutes}m ago)");

        return self::SUCCESS;
    }
}
