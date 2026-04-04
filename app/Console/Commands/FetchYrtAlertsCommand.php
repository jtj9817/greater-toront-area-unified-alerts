<?php

namespace App\Console\Commands;

use App\Events\AlertCreated;
use App\Models\YrtAlert;
use App\Services\Notifications\NotificationAlertFactory;
use App\Services\ServiceAdvisoriesSyncService;
use App\Services\YrtServiceAdvisoriesFeedService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class FetchYrtAlertsCommand extends Command
{
    protected $signature = 'yrt:fetch-alerts';

    protected $description = 'Fetch and sync YRT service advisories';

    public function handle(
        YrtServiceAdvisoriesFeedService $feedService,
        NotificationAlertFactory $notificationAlertFactory,
        ServiceAdvisoriesSyncService $syncService,
    ): int {
        $this->info('Fetching YRT service advisories...');

        try {
            $feedResult = $feedService->fetch();
            $updatedAt = CarbonImmutable::parse($feedResult['updated_at'])->utc();
            $alerts = $feedResult['alerts'];

            $syncResult = $syncService->sync(YrtAlert::class, $alerts, $updatedAt);

            foreach ($syncResult['new_or_reactivated'] as $alert) {
                event(new AlertCreated($notificationAlertFactory->fromYrtAlert($alert)));
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
