<?php

namespace App\Console\Commands;

use App\Events\AlertCreated;
use App\Models\YrtAlert;
use App\Services\Notifications\NotificationAlertFactory;
use App\Services\YrtServiceAdvisoriesFeedService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class FetchYrtAlertsCommand extends Command
{
    protected $signature = 'yrt:fetch-alerts';

    protected $description = 'Fetch and sync YRT service advisories';

    public function handle(
        YrtServiceAdvisoriesFeedService $feedService,
        NotificationAlertFactory $notificationAlertFactory,
    ): int {
        $this->info('Fetching YRT service advisories...');

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

                    $existing = YrtAlert::query()->where('external_id', $externalId)->first();
                    $wasInactiveOrNew = $existing === null || ! $existing->is_active;

                    $alertData['is_active'] = true;
                    $alertData['feed_updated_at'] = $updatedAt;

                    $alert = YrtAlert::query()->updateOrCreate(
                        ['external_id' => $externalId],
                        $alertData,
                    );

                    if ($wasInactiveOrNew) {
                        $newOrReactivatedAlerts[] = $alert;
                    }
                }

                $deactivationQuery = YrtAlert::query()->where('is_active', true);

                if ($syncedExternalIds !== []) {
                    $deactivationQuery->whereNotIn('external_id', $syncedExternalIds);
                }

                $deactivatedCount = $deactivationQuery->update(['is_active' => false]);
            });

            foreach ($newOrReactivatedAlerts as $alert) {
                event(new AlertCreated($notificationAlertFactory->fromYrtAlert($alert)));
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
