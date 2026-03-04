<?php

namespace App\Console\Commands;

use App\Events\AlertCreated;
use App\Models\PoliceCall;
use App\Services\Notifications\NotificationAlertFactory;
use App\Services\TorontoPoliceFeedService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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
            try {
                $calls = $service->fetch();
            } catch (Throwable $e) {
                Log::error('Toronto Police feed fetch failed', [
                    'exception' => $e,
                    'command' => $this->getName(),
                ]);

                $this->error("Failed to fetch police calls: {$e->getMessage()}");

                return self::FAILURE;
            }

            $this->info('Found '.count($calls).' calls in the feed. Updating database...');

            $objectIdsInFeed = [];
            $feedUpdatedAt = Carbon::now();
            $partialFetch = $service->lastFetchWasPartial();
            $resetDetected = false;
            $alertsToDispatch = [];

            if ($partialFetch) {
                Log::warning('Toronto Police feed fetch was partial; skipping deactivation to prevent false negatives', [
                    'command' => $this->getName(),
                    'records_processed' => count($calls),
                ]);

                $this->warn('Police feed pagination was partial; stale call deactivation will be skipped for this run.');
            }

            // Detect an ArcGIS OBJECTID sequence reset (layer rebuild).
            // When TPS recreates the FeatureServer layer the OBJECTID counter restarts
            // from 1, making incoming IDs far smaller than the DB's historic maximum.
            // In that case the existing rows are stale artefacts of the old sequence and
            // must be cleared so that every incoming call is treated as genuinely new
            // (wasRecentlyCreated = true → AlertCreated fires correctly).
            if (! $partialFetch && $calls !== []) {
                $feedMaxId = max(array_column($calls, 'object_id'));
                $dbMaxId = PoliceCall::max('object_id') ?? 0;
                $threshold = (float) config('feeds.police.reset_detection_threshold', 0.1);

                if ($dbMaxId > 0 && ($feedMaxId / $dbMaxId) < $threshold) {
                    Log::warning('Toronto Police ArcGIS OBJECTID sequence reset detected; clearing stale rows', [
                        'db_max_object_id' => $dbMaxId,
                        'feed_max_object_id' => $feedMaxId,
                        'reset_ratio' => $feedMaxId / $dbMaxId,
                        'threshold' => $threshold,
                        'command' => $this->getName(),
                    ]);

                    $this->warn("ArcGIS OBJECTID sequence reset detected (feed max: {$feedMaxId}, DB max: {$dbMaxId}). Clearing stale rows.");
                    $resetDetected = true;
                }
            }

            $persistCall = function (array $callData) use (&$objectIdsInFeed, &$alertsToDispatch, $feedUpdatedAt, $notificationAlertFactory, $resetDetected): void {
                $objectIdsInFeed[] = $callData['object_id'];

                try {
                    $policeCall = PoliceCall::updateOrCreate(
                        ['object_id' => $callData['object_id']],
                        array_merge($callData, [
                            'is_active' => true,
                            'feed_updated_at' => $feedUpdatedAt,
                        ])
                    );

                    if ($policeCall->wasRecentlyCreated || ($policeCall->wasChanged('is_active') && $policeCall->is_active)) {
                        $alert = $notificationAlertFactory->fromPoliceCall($policeCall);

                        if ($resetDetected) {
                            $alertsToDispatch[] = $alert;
                        } else {
                            event(new AlertCreated($alert));
                        }
                    }
                } catch (Throwable $exception) {
                    if ($exception instanceof QueryException) {
                        throw $exception;
                    }

                    Log::warning('Skipping police call record due to persistence failure', [
                        'exception' => $exception,
                        'command' => $this->getName(),
                        'object_id' => $callData['object_id'] ?? null,
                    ]);

                    $this->warn("Skipping police call {$callData['object_id']} due to persistence failure: {$exception->getMessage()}");
                }
            };

            if ($resetDetected) {
                DB::transaction(function () use ($calls, $persistCall): void {
                    PoliceCall::query()->delete();

                    foreach ($calls as $callData) {
                        $persistCall($callData);
                    }
                });

                foreach ($alertsToDispatch as $alert) {
                    event(new AlertCreated($alert));
                }
            } else {
                foreach ($calls as $callData) {
                    $persistCall($callData);
                }
            }

            // Deactivate calls no longer in the feed
            $deactivatedCount = 0;

            if (! $partialFetch) {
                $deactivatedCount = PoliceCall::where('is_active', true)
                    ->whereNotIn('object_id', $objectIdsInFeed)
                    ->update([
                        'is_active' => false,
                        'updated_at' => Carbon::now(),
                    ]);
            }

            $this->info("Successfully updated police calls. Deactivated {$deactivatedCount} stale calls.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('FetchPoliceCallsCommand failed', [
                'exception' => $e,
                'command' => $this->getName(),
            ]);

            $this->error("Command failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
