<?php

namespace App\Console\Commands;

use App\Events\AlertCreated;
use App\Models\DrtAlert;
use App\Services\DrtServiceAlertsFeedService;
use App\Services\Notifications\NotificationAlertFactory;
use App\Services\ServiceAdvisoriesSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class FetchDrtAlertsCommand extends Command
{
    protected $signature = 'drt:fetch-alerts';

    protected $description = 'Fetch and sync DRT service alerts';

    public function handle(
        DrtServiceAlertsFeedService $feedService,
        NotificationAlertFactory $notificationAlertFactory,
        ServiceAdvisoriesSyncService $syncService,
    ): int {
        $this->info('Fetching DRT service alerts...');

        try {
            $feedResult = $feedService->fetch();
            $updatedAt = CarbonImmutable::parse($feedResult['updated_at'])->utc();
            $alerts = $feedResult['alerts'];

            $syncResult = $syncService->sync(DrtAlert::class, $alerts, $updatedAt);

            foreach ($syncResult['new_or_reactivated'] as $alert) {
                event(new AlertCreated($notificationAlertFactory->fromDrtAlert($alert)));
            }

            $this->info(sprintf(
                'Done. %d active alerts synced, %d marked inactive. Feed time: %s',
                count($syncResult['synced_external_ids']),
                $syncResult['deactivated_count'],
                $updatedAt->toDateTimeString(),
            ));
        } catch (Throwable $exception) {
            $this->error('Feed fetch failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
