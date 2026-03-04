<?php

namespace App\Jobs;

use App\Services\Notifications\NotificationAlert;
use App\Services\Notifications\NotificationMatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FanOutAlertNotificationsJob implements ShouldQueue
{
    use Queueable;

    public const RECIPIENT_CHUNK_SIZE = 250;

    /**
     * How long (in minutes) to suppress re-runs of the same alert state before
     * allowing a new fan-out.  Aligned to the slowest feed cadence (police, 10 min).
     */
    public const DEDUPE_TTL_MINUTES = 10;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload,
    ) {}

    public function handle(NotificationMatcher $matcher): void
    {
        $alert = NotificationAlert::fromPayload($this->payload);

        if ($alert->alertId === '' || $alert->source === '') {
            return;
        }

        // Suppress duplicate fan-outs for the same alert state within the dedupe window.
        // The state fingerprint (severity + summary) changes when the alert materially
        // escalates or updates, allowing a new notification to go out for that state.
        $stateFingerprint = md5("{$alert->severity}:{$alert->summary}");
        $dedupeKey = "fanout_dedupe:{$alert->source}:{$alert->alertId}:{$stateFingerprint}";

        if (! Cache::add($dedupeKey, 1, now()->addMinutes(self::DEDUPE_TTL_MINUTES))) {
            Log::debug('FanOutAlertNotificationsJob: suppressed duplicate fan-out for same alert state', [
                'alert_id' => $alert->alertId,
                'source' => $alert->source,
            ]);

            return;
        }

        $matcher
            ->matchingPreferences($alert)
            ->chunk(self::RECIPIENT_CHUNK_SIZE)
            ->each(function ($preferences): void {
                $userIds = $preferences
                    ->pluck('user_id')
                    ->map(static fn (int|string $userId): int => (int) $userId)
                    ->unique()
                    ->values()
                    ->all();

                if ($userIds === []) {
                    return;
                }

                DispatchAlertNotificationChunkJob::dispatch(
                    userIds: $userIds,
                    payload: $this->payload,
                );
            });
    }
}
