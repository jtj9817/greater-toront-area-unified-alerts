<?php

namespace App\Console\Commands;

use App\Models\NotificationLog;
use Illuminate\Console\Command;

class PruneNotificationsCommand extends Command
{
    protected $signature = 'notifications:prune';

    protected $description = 'Delete notification logs older than 30 days';

    public function handle(): int
    {
        $cutoff = now()->subDays(30);

        $prunedCount = NotificationLog::query()
            ->where('sent_at', '<', $cutoff)
            ->delete();

        $this->info(sprintf(
            'Pruned %d notification log(s) older than 30 days.',
            $prunedCount,
        ));

        return self::SUCCESS;
    }
}
