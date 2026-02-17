<?php

namespace App\Console\Commands;

use App\Events\AlertCreated;
use App\Models\FireIncident;
use App\Services\FeedDataSanity;
use App\Services\Notifications\NotificationAlertFactory;
use App\Services\SceneIntel\SceneIntelProcessor;
use App\Services\TorontoFireFeedService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class FetchFireIncidentsCommand extends Command
{
    protected $signature = 'fire:fetch-incidents';

    protected $description = 'Fetch active fire incidents from the Toronto Fire Services CAD feed';

    public function handle(
        TorontoFireFeedService $service,
        NotificationAlertFactory $notificationAlertFactory,
        SceneIntelProcessor $sceneIntelProcessor,
        FeedDataSanity $feedDataSanity,
    ): int {
        $this->info('Fetching Toronto Fire active incidents...');

        try {
            try {
                $data = $service->fetch();
                $feedUpdatedAt = Carbon::parse($data['updated_at'], 'America/Toronto')->utc();
                $feedDataSanity->warnIfFutureTimestamp($feedUpdatedAt, 'toronto_fire', 'updated_at', [
                    'command' => $this->getName(),
                ]);
            } catch (Throwable $e) {
                Log::error('Toronto Fire feed fetch failed', [
                    'exception' => $e,
                    'command' => $this->getName(),
                ]);

                $this->error("Feed fetch failed: {$e->getMessage()}");

                return self::FAILURE;
            }

            $activeEventNums = [];
            $existingIncidentsByEventNum = collect();
            $sceneIntelAttempts = 0;
            $sceneIntelFailures = 0;

            if ($data['events'] !== []) {
                $incomingEventNums = array_values(array_unique(array_map(
                    static fn (array $event): string => $event['event_num'],
                    $data['events'],
                )));

                $existingIncidentsByEventNum = FireIncident::query()
                    ->select(['id', 'event_num', 'alarm_level', 'units_dispatched', 'is_active'])
                    ->whereIn('event_num', $incomingEventNums)
                    ->get()
                    ->keyBy('event_num');
            }

            foreach ($data['events'] as $event) {
                $activeEventNums[] = $event['event_num'];
                $previousData = $existingIncidentsByEventNum
                    ->get($event['event_num'])
                    ?->only(['alarm_level', 'units_dispatched', 'is_active']);

                try {
                    $dispatchTime = Carbon::parse($event['dispatch_time'], 'America/Toronto')->utc();
                    $feedDataSanity->warnIfFutureTimestamp($dispatchTime, 'toronto_fire', 'dispatch_time', [
                        'command' => $this->getName(),
                        'event_num' => $event['event_num'] ?? null,
                    ]);
                } catch (Throwable $e) {
                    Log::warning('Skipping fire incident due to dispatch_time parse failure', [
                        'exception' => $e,
                        'command' => $this->getName(),
                        'event_num' => $event['event_num'] ?? null,
                        'dispatch_time' => $event['dispatch_time'] ?? null,
                    ]);

                    $this->warn("Skipping event {$event['event_num']} due to dispatch_time parse failure: {$e->getMessage()}");

                    continue;
                }

                $incident = FireIncident::updateOrCreate(
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

                if ($incident->wasRecentlyCreated || ($incident->wasChanged('is_active') && $incident->is_active)) {
                    event(new AlertCreated(
                        $notificationAlertFactory->fromFireIncident($incident),
                    ));
                }

                $sceneIntelAttempts++;

                try {
                    $sceneIntelProcessor->processIncidentUpdate($incident, $previousData);
                } catch (Throwable $e) {
                    $sceneIntelFailures++;

                    Log::warning('Scene intel generation failed for fire incident', [
                        'exception' => $e,
                        'command' => $this->getName(),
                        'event_num' => $incident->event_num,
                    ]);

                    $this->error("Failed to generate scene intel for event {$incident->event_num}: {$e->getMessage()}");
                }
            }

            $deactivationQuery = FireIncident::query()->where('is_active', true);

            if ($activeEventNums !== []) {
                $deactivationQuery->whereNotIn('event_num', $activeEventNums);
            }

            $deactivatedIncidents = $deactivationQuery->get();
            $deactivated = $deactivatedIncidents->count();

            if ($deactivatedIncidents->isNotEmpty()) {
                FireIncident::query()
                    ->whereIn('id', $deactivatedIncidents->pluck('id'))
                    ->update(['is_active' => false]);

                foreach ($deactivatedIncidents as $deactivatedIncident) {
                    $previousData = $deactivatedIncident->only(['alarm_level', 'units_dispatched', 'is_active']);
                    $deactivatedIncident->is_active = false;

                    $sceneIntelAttempts++;

                    try {
                        $sceneIntelProcessor->processIncidentUpdate($deactivatedIncident, $previousData);
                    } catch (Throwable $e) {
                        $sceneIntelFailures++;

                        Log::warning('Scene intel generation failed for deactivated fire incident', [
                            'exception' => $e,
                            'command' => $this->getName(),
                            'event_num' => $deactivatedIncident->event_num,
                        ]);

                        $this->error("Failed to generate scene intel for deactivated event {$deactivatedIncident->event_num}: {$e->getMessage()}");
                    }
                }
            }

            if ($sceneIntelAttempts > 0) {
                $failureRate = $sceneIntelFailures / $sceneIntelAttempts;
                $threshold = 0.5;

                if ($failureRate > $threshold) {
                    Log::warning('Scene intel failure rate exceeded threshold', [
                        'command' => $this->getName(),
                        'attempts' => $sceneIntelAttempts,
                        'failures' => $sceneIntelFailures,
                        'failure_rate' => $failureRate,
                        'threshold' => $threshold,
                    ]);

                    $this->warn(sprintf(
                        'Scene intel failures exceeded threshold: %d/%d (%.0f%%)',
                        $sceneIntelFailures,
                        $sceneIntelAttempts,
                        $failureRate * 100,
                    ));
                }
            }

            $this->info(sprintf(
                'Done. %d active incidents synced, %d marked inactive. Feed time: %s',
                count($activeEventNums),
                $deactivated,
                $feedUpdatedAt->toDateTimeString(),
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('FetchFireIncidentsCommand failed', [
                'exception' => $e,
                'command' => $this->getName(),
            ]);

            $this->error("Command failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
