<?php

namespace App\Console\Commands;

use App\Events\AlertCreated;
use App\Models\DrtAlert;
use App\Services\DrtServiceAlertsFeedService;
use App\Services\Notifications\NotificationAlertFactory;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class FetchDrtAlertsCommand extends Command
{
    protected $signature = 'drt:fetch-alerts';

    protected $description = 'Fetch and sync DRT service alerts';

    public function handle(
        DrtServiceAlertsFeedService $feedService,
        NotificationAlertFactory $notificationAlertFactory,
    ): int {
        $this->info('Fetching DRT service alerts...');

        try {
            $feedResult = $feedService->fetch();
            $updatedAt = CarbonImmutable::parse($feedResult['updated_at'])->utc();
            $alerts = $feedResult['alerts'];

            $syncedExternalIds = [];
            $newOrReactivatedAlerts = [];
            $deactivatedCount = 0;

            DB::transaction(function () use ($alerts, $updatedAt, &$syncedExternalIds, &$newOrReactivatedAlerts, &$deactivatedCount): void {
                foreach ($alerts as $alertData) {
                    $externalId = (string) $alertData['external_id'];
                    $syncedExternalIds[] = $externalId;

                    $existing = DrtAlert::query()->where('external_id', $externalId)->first();
                    $wasInactiveOrNew = $existing === null || ! $existing->is_active;

                    $alertData['is_active'] = true;
                    $alertData['feed_updated_at'] = $updatedAt;

                    $alert = DrtAlert::query()->updateOrCreate(
                        ['external_id' => $externalId],
                        $alertData,
                    );

                    if ($wasInactiveOrNew) {
                        $newOrReactivatedAlerts[] = $alert;
                    }
                }

                $deactivationQuery = DrtAlert::query()->where('is_active', true);

                if ($syncedExternalIds !== []) {
                    $deactivationQuery->whereNotIn('external_id', $syncedExternalIds);
                }

                $deactivatedCount = $deactivationQuery->update(['is_active' => false]);
            });

            foreach ($newOrReactivatedAlerts as $alert) {
                event(new AlertCreated($notificationAlertFactory->fromDrtAlert($alert)));
            }

            $this->info(sprintf(
                'Done. %d active alerts synced, %d marked inactive. Feed time: %s',
                count($syncedExternalIds),
                $deactivatedCount,
                $updatedAt->toDateTimeString(),
            ));
        } catch (Throwable $exception) {
            $this->error('Feed fetch failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
