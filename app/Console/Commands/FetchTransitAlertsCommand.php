<?php

namespace App\Console\Commands;

use App\Events\AlertCreated;
use App\Models\TransitAlert;
use App\Services\Notifications\NotificationAlertFactory;
use App\Services\TtcAlertsFeedService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class FetchTransitAlertsCommand extends Command
{
    protected $signature = 'transit:fetch-alerts';

    protected $description = 'Fetch active transit alerts from TTC sources';

    public function handle(
        TtcAlertsFeedService $service,
        NotificationAlertFactory $notificationAlertFactory,
    ): int {
        $this->info('Fetching TTC transit alerts...');

        try {
            $data = $service->fetch();
            $feedUpdatedAt = Carbon::instance($data['updated_at'])->utc();
        } catch (\Throwable $exception) {
            $this->error("Feed fetch failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $activeExternalIds = [];

        foreach ($data['alerts'] as $alert) {
            $externalId = $alert['external_id'] ?? null;
            if (! is_string($externalId) || $externalId === '') {
                continue;
            }

            $activeExternalIds[$externalId] = true;

            $transitAlert = TransitAlert::updateOrCreate(
                ['external_id' => $externalId],
                array_merge($alert, [
                    'is_active' => true,
                    'feed_updated_at' => $feedUpdatedAt,
                ])
            );

            if ($transitAlert->wasRecentlyCreated || ($transitAlert->wasChanged('is_active') && $transitAlert->is_active)) {
                event(new AlertCreated(
                    $notificationAlertFactory->fromTransitAlert($transitAlert),
                ));
            }
        }

        $staleQuery = TransitAlert::query()->where('is_active', true);

        if ($activeExternalIds !== []) {
            $staleQuery->whereNotIn('external_id', array_keys($activeExternalIds));
        }

        $deactivated = $staleQuery->update(['is_active' => false]);

        $this->info(sprintf(
            'Done. %d active alerts synced, %d marked inactive. Feed time: %s',
            count($activeExternalIds),
            $deactivated,
            $feedUpdatedAt->toDateTimeString(),
        ));

        return self::SUCCESS;
    }
}
