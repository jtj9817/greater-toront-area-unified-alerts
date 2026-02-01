<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class TorontoFireFeedService
{
    const FEED_URL = 'https://www.toronto.ca/data/fire/livecad.xml';

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
     *     beat: string,
     *     units_dispatched: ?string,
     * }>}
     */
    public function fetch(): array
    {
        $cacheBuster = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz'), 0, 6);

        $response = Http::timeout(15)
            ->withHeaders(['Accept' => 'application/xml'])
            ->get(self::FEED_URL, [$cacheBuster => '']);

        $response->throw();

        $xml = simplexml_load_string($response->body());

        if ($xml === false) {
            throw new \RuntimeException('Failed to parse Toronto Fire XML feed');
        }

        $updatedAt = (string) $xml->update_from_db_time;
        $events = [];

        foreach ($xml->event as $event) {
            $events[] = [
                'event_num' => (string) $event->event_num,
                'event_type' => (string) $event->event_type,
                'prime_street' => trim((string) $event->prime_street) ?: null,
                'cross_streets' => trim((string) $event->cross_streets) ?: null,
                'dispatch_time' => (string) $event->dispatch_time,
                'alarm_level' => (int) $event->alarm_lev,
                'beat' => (string) $event->beat,
                'units_dispatched' => trim((string) $event->units_disp) ?: null,
            ];
        }

        return [
            'updated_at' => $updatedAt,
            'events' => $events,
        ];
    }
}
