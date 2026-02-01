<?php

namespace App\Console\Commands;

use App\Models\FireIncident;
use App\Services\TorontoFireFeedService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class FetchFireIncidentsCommand extends Command
{
    protected $signature = 'fire:fetch-incidents';

    protected $description = 'Fetch active fire incidents from the Toronto Fire Services CAD feed';

    public function handle(TorontoFireFeedService $service): int
    {
        $this->info('Fetching Toronto Fire active incidents...');

        try {
            $data = $service->fetch();
            $feedUpdatedAt = Carbon::parse($data['updated_at'], 'America/Toronto')->utc();
        } catch (\Throwable $e) {
            $this->error("Feed fetch failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $activeEventNums = [];

        foreach ($data['events'] as $event) {
            $activeEventNums[] = $event['event_num'];

            try {
                $dispatchTime = Carbon::parse($event['dispatch_time'], 'America/Toronto')->utc();
            } catch (\Throwable $e) {
                $this->error("Failed to parse dispatch_time for event {$event['event_num']}: {$e->getMessage()}");

                return self::FAILURE;
            }

            FireIncident::updateOrCreate(
                ['event_num' => $event['event_num']],
                [
                    'event_type' => $event['event_type'],
                    'prime_street' => $event['prime_street'],
                    'cross_streets' => $event['cross_streets'],
                    'dispatch_time' => $dispatchTime,
                    'alarm_level' => $event['alarm_level'],
                    'beat' => $event['beat'],
                    'units_dispatched' => $event['units_dispatched'],
                    'is_active' => true,
                    'feed_updated_at' => $feedUpdatedAt,
                ]
            );
        }

        // Mark incidents no longer in the feed as inactive
        $deactivated = FireIncident::where('is_active', true)
            ->whereNotIn('event_num', $activeEventNums)
            ->update(['is_active' => false]);

        $this->info(sprintf(
            'Done. %d active incidents synced, %d marked inactive. Feed time: %s',
            count($activeEventNums),
            $deactivated,
            $feedUpdatedAt->toDateTimeString(),
        ));

        return self::SUCCESS;
    }
}
