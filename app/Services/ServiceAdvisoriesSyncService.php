<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ServiceAdvisoriesSyncService
{
    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @param  list<array<string, mixed>>  $alerts
     * @return array{
     *   synced_external_ids: list<string>,
     *   new_or_reactivated: list<TModel>,
     *   deactivated_count: int
     * }
     */
    public function sync(string $modelClass, array $alerts, CarbonImmutable $updatedAt): array
    {
        $syncedExternalIds = [];
        $newOrReactivatedAlerts = [];
        $deactivatedCount = 0;

        DB::transaction(function () use (
            $modelClass,
            $alerts,
            $updatedAt,
            &$syncedExternalIds,
            &$newOrReactivatedAlerts,
            &$deactivatedCount,
        ): void {
            foreach ($alerts as $alertData) {
                $externalId = $alertData['external_id'] ?? null;
                $externalId = is_scalar($externalId) ? trim((string) $externalId) : '';

                if ($externalId === '') {
                    continue;
                }

                $syncedExternalIds[] = $externalId;

                $alertData['is_active'] = true;
                $alertData['feed_updated_at'] = $updatedAt;

                /** @var TModel $alert */
                $alert = $modelClass::query()->updateOrCreate(
                    ['external_id' => $externalId],
                    $alertData,
                );

                if ($alert->wasRecentlyCreated || ($alert->wasChanged('is_active') && $alert->is_active)) {
                    $newOrReactivatedAlerts[] = $alert;
                }
            }

            $deactivationQuery = $modelClass::query()->where('is_active', true);
            $syncedExternalIdsForNotIn = array_values(array_unique($syncedExternalIds));

            if ($syncedExternalIdsForNotIn !== []) {
                $deactivationQuery->whereNotIn('external_id', $syncedExternalIdsForNotIn);
            }

            $deactivatedCount = $deactivationQuery->update(['is_active' => false]);
        });

        return [
            'synced_external_ids' => $syncedExternalIds,
            'new_or_reactivated' => $newOrReactivatedAlerts,
            'deactivated_count' => $deactivatedCount,
        ];
    }
}
