<?php

namespace App\Console\Commands;

use App\Events\AlertCreated;
use App\Models\PoliceCall;
use App\Services\Notifications\NotificationAlertFactory;
use App\Services\TorontoPoliceFeedService;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FetchPoliceCallsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'police:fetch-calls';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch active police calls from the Toronto Police Service feed';

    /**
     * Execute the console command.
     */
    public function handle(
        TorontoPoliceFeedService $service,
        NotificationAlertFactory $notificationAlertFactory,
    ): int {
        $this->info('Fetching police calls from Toronto Police Service...');

        try {
            $calls = $service->fetch();
        } catch (Exception $e) {
            $this->error("Failed to fetch police calls: {$e->getMessage()}");
            Log::error("Police Feed Error: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info('Found '.count($calls).' calls in the feed. Updating database...');

        $objectIdsInFeed = [];
        $feedUpdatedAt = Carbon::now();

        foreach ($calls as $callData) {
            $objectIdsInFeed[] = $callData['object_id'];

            $policeCall = PoliceCall::updateOrCreate(
                ['object_id' => $callData['object_id']],
                array_merge($callData, [
                    'is_active' => true,
                    'feed_updated_at' => $feedUpdatedAt,
                ])
            );

            if ($policeCall->wasRecentlyCreated || ($policeCall->wasChanged('is_active') && $policeCall->is_active)) {
                event(new AlertCreated(
                    $notificationAlertFactory->fromPoliceCall($policeCall),
                ));
            }
        }

        // Deactivate calls no longer in the feed
        $deactivatedCount = PoliceCall::where('is_active', true)
            ->whereNotIn('object_id', $objectIdsInFeed)
            ->update([
                'is_active' => false,
                'updated_at' => Carbon::now(),
            ]);

        $this->info("Successfully updated police calls. Deactivated {$deactivatedCount} stale calls.");

        return self::SUCCESS;
    }
}
