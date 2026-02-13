<?php

namespace App\Console\Commands;

use App\Events\AlertCreated;
use App\Models\FireIncident;
use App\Services\Notifications\NotificationAlertFactory;
use App\Services\SceneIntel\SceneIntelProcessor;
use App\Services\TorontoFireFeedService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class FetchFireIncidentsCommand extends Command
{
    protected $signature = 'fire:fetch-incidents';

    protected $description = 'Fetch active fire incidents from the Toronto Fire Services CAD feed';

    public function handle(
        TorontoFireFeedService $service,
        NotificationAlertFactory $notificationAlertFactory,
        SceneIntelProcessor $sceneIntelProcessor,
    ): int {
        $this->info('Fetching Toronto Fire active incidents...');

        try {
            $data = $service->fetch();
            $feedUpdatedAt = Carbon::parse($data['updated_at'], 'America/Toronto')->utc();
        } catch (\Throwable $e) {
            $this->error("Feed fetch failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $activeEventNums = [];
        $existingIncidentsByEventNum = collect();

        if ($data['events'] !== []) {
            $incomingEventNums = array_values(array_unique(array_map(
                static fn (array $event): string => $event['event_num'],
                $data['events'],
            )));

            $existingIncidentsByEventNum = FireIncident::query()
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
            } catch (\Throwable $e) {
                $this->error("Failed to parse dispatch_time for event {$event['event_num']}: {$e->getMessage()}");

                return self::FAILURE;
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

            $sceneIntelProcessor->processIncidentUpdate($incident, $previousData);
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

                $sceneIntelProcessor->processIncidentUpdate($deactivatedIncident, $previousData);
            }
        }

        $this->info(sprintf(
            'Done. %d active incidents synced, %d marked inactive. Feed time: %s',
            count($activeEventNums),
            $deactivated,
            $feedUpdatedAt->toDateTimeString(),
        ));

        return self::SUCCESS;
    }
}
