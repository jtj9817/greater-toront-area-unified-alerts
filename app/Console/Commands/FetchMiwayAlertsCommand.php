<?php

namespace App\Console\Commands;

use App\Events\AlertCreated;
use App\Models\MiwayAlert;
use App\Services\MiwayGtfsRtAlertsFeedService;
use App\Services\Notifications\NotificationAlertFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class FetchMiwayAlertsCommand extends Command
{
    protected $signature = 'miway:fetch-alerts';

    protected $description = 'Fetch and sync MiWay GTFS-RT service alerts';

    public function handle(MiwayGtfsRtAlertsFeedService $feedService, NotificationAlertFactory $notificationAlertFactory): int
    {
        $this->info('Fetching MiWay service alerts...');

        try {
            $latestAlert = MiwayAlert::orderByDesc('feed_updated_at')->first();
            $lastModified = $latestAlert?->feed_updated_at?->toRfc7231String();

            $result = $feedService->fetch(null, $lastModified);

            if ($result['not_modified'] ?? false) {
                $this->info('Feed not modified. Exiting.');

                return self::SUCCESS;
            }

            $updatedAt = $result['updated_at'];
            $alerts = $result['alerts'];

            $syncedIds = [];
            $newOrReactivatedModels = [];
            $deactivatedCount = 0;

            DB::transaction(function () use ($alerts, $updatedAt, &$syncedIds, &$newOrReactivatedModels, &$deactivatedCount) {
                foreach ($alerts as $alertData) {
                    $externalId = $alertData['external_id'];
                    $syncedIds[] = $externalId;

                    $existing = MiwayAlert::where('external_id', $externalId)->first();
                    $wasInactiveOrNew = ($existing === null || ! $existing->is_active);

                    $alertData['is_active'] = true;
                    $alertData['feed_updated_at'] = $updatedAt;

                    $model = MiwayAlert::updateOrCreate(
                        ['external_id' => $externalId],
                        $alertData
                    );

                    if ($wasInactiveOrNew) {
                        $newOrReactivatedModels[] = $model;
                    }
                }

                $deactivationQuery = MiwayAlert::where('is_active', true);

                if ($syncedIds !== []) {
                    $deactivationQuery->whereNotIn('external_id', $syncedIds);
                }

                $deactivatedCount = $deactivationQuery->update(['is_active' => false]);
            });

            foreach ($newOrReactivatedModels as $model) {
                event(new AlertCreated($notificationAlertFactory->fromMiwayAlert($model)));
            }

            $this->info(sprintf(
                'Done. %d active alerts synced, %d marked inactive.',
                count($syncedIds),
                $deactivatedCount
            ));

        } catch (Throwable $exception) {
            $this->error('Feed fetch failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
