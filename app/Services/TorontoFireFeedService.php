<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class TorontoFireFeedService
{
    protected const FEED_URL = 'https://www.toronto.ca/data/fire/livecad.xml';

    protected const TIMEOUT_SECONDS = 15;

    /**
     * Fetch and parse the live CAD XML feed.
     *
     * @return array{updated_at: string, events: list<array{
     *     event_num: string,
     *     event_type: string,
     *     prime_street: ?string,
     *     cross_streets: ?string,
     *     dispatch_time: string,
     *     alarm_level: int,
     *     beat: ?string,
     *     units_dispatched: ?string,
     * }>}
     */
    public function fetch(): array
    {
        $cacheBuster = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz'), 0, 6);

        $response = Http::timeout(self::TIMEOUT_SECONDS)
            ->retry(2, 200, throw: false)
            ->withHeaders(['Accept' => 'application/xml, text/xml'])
            ->get(self::FEED_URL, [$cacheBuster => '']);

        if ($response->failed()) {
            $body = trim($response->body());
            $details = $body === '' ? '' : ' - '.substr($body, 0, 200);

            throw new RuntimeException('Toronto Fire feed request failed: '.$response->status().$details);
        }

        $body = $response->body();

        if (trim($body) === '') {
            throw new RuntimeException('Toronto Fire feed returned an empty response body');
        }

        $previousUseInternalErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $xml = simplexml_load_string($body);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseInternalErrors);

        if ($xml === false) {
            $messages = array_values(array_filter(array_map(
                fn ($error) => isset($error->message) ? trim((string) $error->message) : null,
                $errors
            )));

            $suffix = $messages === [] ? '' : ' - '.implode(' | ', array_slice($messages, 0, 3));

            throw new RuntimeException('Failed to parse Toronto Fire XML feed'.$suffix);
        }

        $updatedAt = trim((string) $xml->update_from_db_time);

        if ($updatedAt === '') {
            throw new RuntimeException('Toronto Fire XML feed missing update_from_db_time');
        }

        $events = [];

        foreach ($xml->event as $event) {
            $eventNum = trim((string) $event->event_num);
            $eventType = trim((string) $event->event_type);
            $dispatchTime = trim((string) $event->dispatch_time);

            if ($eventNum === '' || $eventType === '' || $dispatchTime === '') {
                throw new RuntimeException('Toronto Fire XML feed contains an event missing required fields');
            }

            $events[] = [
                'event_num' => $eventNum,
                'event_type' => $eventType,
                'prime_street' => trim((string) $event->prime_street) ?: null,
                'cross_streets' => trim((string) $event->cross_streets) ?: null,
                'dispatch_time' => $dispatchTime,
                'alarm_level' => (int) $event->alarm_lev,
                'beat' => trim((string) $event->beat) ?: null,
                'units_dispatched' => trim((string) $event->units_disp) ?: null,
            ];
        }

        return [
            'updated_at' => $updatedAt,
            'events' => $events,
        ];
    }
}
