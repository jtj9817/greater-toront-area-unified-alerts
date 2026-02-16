<?php

namespace App\Console\Commands;

use App\Models\IncidentUpdate;
use Illuminate\Console\Command;

class PruneSceneIntelCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scene-intel:prune {--days=90 : The number of days to retain data}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune incident updates older than the specified number of days';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $count = IncidentUpdate::query()
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info(sprintf('Pruned %d incident update(s) older than %d days.', $count, $days));

        return self::SUCCESS;
    }
}
