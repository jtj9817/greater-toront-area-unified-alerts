<?php

namespace App\Console\Commands;

use App\Events\AlertCreated;
use App\Models\GoTransitAlert;
use App\Services\GoTransitFeedService;
use App\Services\Notifications\NotificationAlertFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class FetchGoTransitAlertsCommand extends Command
{
    protected $signature = 'go-transit:fetch-alerts';

    protected $description = 'Fetch active service alerts from the GO Transit / Metrolinx API';

    public function handle(
        GoTransitFeedService $service,
        NotificationAlertFactory $notificationAlertFactory,
    ): int {
        $this->info('Fetching GO Transit service alerts...');

        try {
            $data = $service->fetch();
            $feedUpdatedAt = Carbon::parse($data['updated_at'])->utc();
        } catch (\Throwable $e) {
            $this->error("Feed fetch failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $activeExternalIds = [];

        foreach ($data['alerts'] as $alert) {
            $activeExternalIds[] = $alert['external_id'];

            try {
                $postedAt = Carbon::parse($alert['posted_at'], 'America/Toronto')->utc();
            } catch (\Throwable $e) {
                $this->error("Failed to parse posted_at for alert {$alert['external_id']}: {$e->getMessage()}");

                return self::FAILURE;
            }

            $goTransitAlert = GoTransitAlert::updateOrCreate(
                ['external_id' => $alert['external_id']],
                [
                    'alert_type' => $alert['alert_type'],
                    'service_mode' => $alert['service_mode'],
                    'corridor_or_route' => $alert['corridor_or_route'],
                    'corridor_code' => $alert['corridor_code'],
                    'sub_category' => $alert['sub_category'],
                    'message_subject' => $alert['message_subject'],
                    'message_body' => $alert['message_body'],
                    'direction' => $alert['direction'],
                    'trip_number' => $alert['trip_number'],
                    'delay_duration' => $alert['delay_duration'],
                    'status' => $alert['status'],
                    'line_colour' => $alert['line_colour'],
                    'posted_at' => $postedAt,
                    'is_active' => true,
                    'feed_updated_at' => $feedUpdatedAt,
                ]
            );

            if ($goTransitAlert->wasRecentlyCreated || ($goTransitAlert->wasChanged('is_active') && $goTransitAlert->is_active)) {
                event(new AlertCreated(
                    $notificationAlertFactory->fromGoTransitAlert($goTransitAlert),
                ));
            }
        }

        $deactivated = GoTransitAlert::where('is_active', true)
            ->whereNotIn('external_id', $activeExternalIds)
            ->update(['is_active' => false]);

        $this->info(sprintf(
            'Done. %d active alerts synced, %d marked inactive. Feed time: %s',
            count($activeExternalIds),
            $deactivated,
            $feedUpdatedAt->toDateTimeString(),
        ));

        return self::SUCCESS;
    }
}
