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

            /** @var TransitAlert|null $existing */
            $existing = TransitAlert::query()->where('external_id', $externalId)->first();
            $previousEffect = $existing?->effect;
            $previousActive = $existing?->is_active ?? false;

            $transitAlert = TransitAlert::updateOrCreate(
                ['external_id' => $externalId],
                array_merge($alert, [
                    'is_active' => true,
                    'feed_updated_at' => $feedUpdatedAt,
                ])
            );

            if ($this->shouldDispatchNotification(
                transitAlert: $transitAlert,
                previousEffect: $previousEffect,
                wasPreviouslyActive: $previousActive,
            )) {
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

    private function shouldDispatchNotification(TransitAlert $transitAlert, ?string $previousEffect, bool $wasPreviouslyActive): bool
    {
        if ($transitAlert->source_feed === 'ttc_accessibility') {
            // Dispatch if status changed, even if new status is IN_SERVICE
            if ($previousEffect !== null && $previousEffect !== $transitAlert->effect) {
                return true;
            }

            if (! $this->isOutOfServiceEffect($transitAlert->effect)) {
                return false;
            }

            if ($transitAlert->wasRecentlyCreated) {
                return true;
            }

            return ! $this->isOutOfServiceEffect($previousEffect);
        }

        if ($transitAlert->wasRecentlyCreated) {
            return true;
        }

        return ! $wasPreviouslyActive && $transitAlert->is_active;
    }

    private function isOutOfServiceEffect(?string $effect): bool
    {
        if ($effect === null) {
            return false;
        }

        $normalized = strtoupper(trim($effect));

        return $normalized === 'OUT_OF_SERVICE'
            || str_contains($normalized, 'NOT_IN_SERVICE')
            || str_contains($normalized, 'UNAVAILABLE');
    }
}
