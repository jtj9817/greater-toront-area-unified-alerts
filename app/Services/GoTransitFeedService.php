<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class GoTransitFeedService
{
    protected const FEED_URL = 'https://api.metrolinx.com/external/go/serviceupdate/en/all';

    protected const TIMEOUT_SECONDS = 15;

    public function __construct(
        protected FeedCircuitBreaker $circuitBreaker,
    ) {}

    /**
     * Fetch and parse GO Transit service alerts from the Metrolinx API.
     *
     * @return array{updated_at: string, alerts: list<array{
     *     external_id: string,
     *     alert_type: string,
     *     service_mode: string,
     *     corridor_or_route: string,
     *     corridor_code: ?string,
     *     sub_category: ?string,
     *     message_subject: string,
     *     message_body: ?string,
     *     direction: ?string,
     *     trip_number: ?string,
     *     delay_duration: ?string,
     *     status: ?string,
     *     line_colour: ?string,
     *     posted_at: string,
     * }>}
     */
    public function fetch(): array
    {
        $allowEmptyFeeds = (bool) config('feeds.allow_empty_feeds');
        $this->circuitBreaker->throwIfOpen('go_transit');

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->retry(2, 200, throw: false)
                ->acceptJson()
                ->get(self::FEED_URL);

            if ($response->failed()) {
                $body = trim($response->body());
                $details = $body === '' ? '' : ' - '.substr($body, 0, 200);

                throw new RuntimeException('GO Transit feed request failed: '.$response->status().$details);
            }

            $json = $response->json();

            if (! is_array($json)) {
                throw new RuntimeException('GO Transit feed returned invalid JSON');
            }

            $updatedAt = trim((string) ($json['LastUpdated'] ?? ''));

            if ($updatedAt === '') {
                throw new RuntimeException('GO Transit feed missing LastUpdated');
            }

            $alerts = [];

            $this->parseTrains($json, $alerts);
            $this->parseBuses($json, $alerts);
            $this->parseStations($json, $alerts);

            if ($alerts === [] && ! $allowEmptyFeeds) {
                throw new RuntimeException('GO Transit feed returned zero alerts');
            }

            $result = [
                'updated_at' => $updatedAt,
                'alerts' => $alerts,
            ];

            $this->circuitBreaker->recordSuccess('go_transit');

            return $result;
        } catch (Throwable $exception) {
            $this->circuitBreaker->recordFailure('go_transit', $exception);
            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $json
     * @param  list<array>  $alerts
     */
    private function parseTrains(array $json, array &$alerts): void
    {
        $trains = $json['Trains']['Train'] ?? [];

        if (! is_array($trains)) {
            return;
        }

        foreach ($trains as $train) {
            if (! is_array($train)) {
                continue;
            }

            $code = trim((string) ($train['Code'] ?? ''));
            $name = trim((string) ($train['Name'] ?? ''));
            $lineColour = $this->extractLineColour($train);

            $this->parseNotifications($train, 'GO Train', $code, $name, $lineColour, $alerts);
            $this->parseSaagNotifications($train, $code, $name, $lineColour, $alerts);
        }
    }

    /**
     * @param  array<string, mixed>  $json
     * @param  list<array>  $alerts
     */
    private function parseBuses(array $json, array &$alerts): void
    {
        $buses = $json['Buses']['Bus'] ?? [];

        if (! is_array($buses)) {
            return;
        }

        foreach ($buses as $bus) {
            if (! is_array($bus)) {
                continue;
            }

            $code = trim((string) ($bus['Code'] ?? ''));
            $name = trim((string) ($bus['Name'] ?? ''));
            $lineColour = $this->extractLineColour($bus);

            $this->parseNotifications($bus, 'GO Bus', $code, $name, $lineColour, $alerts);
        }
    }

    /**
     * @param  array<string, mixed>  $json
     * @param  list<array>  $alerts
     */
    private function parseStations(array $json, array &$alerts): void
    {
        $stations = $json['Stations']['Station'] ?? [];

        if (! is_array($stations)) {
            return;
        }

        foreach ($stations as $station) {
            if (! is_array($station)) {
                continue;
            }

            $code = trim((string) ($station['Code'] ?? ''));
            $name = trim((string) ($station['Name'] ?? ''));
            $lineColour = $this->extractLineColour($station);

            $this->parseNotifications($station, 'Station', $code, $name, $lineColour, $alerts);
        }
    }

    /**
     * @param  array<string, mixed>  $entity
     * @param  list<array>  $alerts
     */
    private function parseNotifications(array $entity, string $serviceMode, string $code, string $name, ?string $lineColour, array &$alerts): void
    {
        $notifications = $entity['Notifications']['Notification'] ?? [];

        if (! is_array($notifications)) {
            return;
        }

        foreach ($notifications as $notification) {
            if (! is_array($notification)) {
                continue;
            }

            $subject = trim((string) ($notification['MessageSubject'] ?? ''));
            $subCategory = trim((string) ($notification['SubCategory'] ?? '')) ?: null;

            if ($subject === '') {
                continue;
            }

            $externalId = 'notif:'.$code.':'.$subCategory.':'.md5($subject);

            $body = trim((string) ($notification['MessageBody'] ?? ''));
            $body = $body !== '' ? strip_tags($body) : null;

            $postedAt = trim((string) ($notification['PostedDateTime'] ?? ''));

            if ($postedAt === '') {
                continue;
            }

            $alerts[] = [
                'external_id' => $externalId,
                'alert_type' => 'notification',
                'service_mode' => $serviceMode,
                'corridor_or_route' => $name ?: $code,
                'corridor_code' => $code ?: null,
                'sub_category' => $subCategory,
                'message_subject' => $subject,
                'message_body' => $body,
                'direction' => null,
                'trip_number' => null,
                'delay_duration' => null,
                'status' => trim((string) ($notification['Status'] ?? '')) ?: null,
                'line_colour' => $lineColour,
                'posted_at' => $postedAt,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $train
     * @param  list<array>  $alerts
     */
    private function parseSaagNotifications(array $train, string $code, string $name, ?string $lineColour, array &$alerts): void
    {
        $saagNotifications = $train['SaagNotifications']['SaagNotification'] ?? [];

        if (! is_array($saagNotifications)) {
            return;
        }

        foreach ($saagNotifications as $saag) {
            if (! is_array($saag)) {
                continue;
            }

            $tripNumbers = $saag['TripNumbers'] ?? [];

            if (! is_array($tripNumbers) || $tripNumbers === []) {
                continue;
            }

            $tripNumber = (string) $tripNumbers[0];
            $externalId = 'saag:'.$code.':'.$tripNumber;

            $direction = trim((string) ($saag['Direction'] ?? '')) ?: null;
            $headSign = trim((string) ($saag['HeadSign'] ?? ''));
            $delayDuration = trim((string) ($saag['DelayDuration'] ?? '')) ?: null;
            $departureTime = trim((string) ($saag['DepartureTimeDisplay'] ?? ''));
            $arrivalTime = trim((string) ($saag['ArrivalTimeTimeDisplay'] ?? ''));
            $status = trim((string) ($saag['Status'] ?? '')) ?: null;

            $subject = $headSign !== ''
                ? "{$name} - {$headSign} delayed"
                : "{$name} train delayed";

            if ($delayDuration !== null && $delayDuration !== '00:00:00') {
                $subject .= " ({$delayDuration})";
            }

            $bodyParts = [];
            if ($departureTime !== '') {
                $bodyParts[] = "Departure: {$departureTime}";
            }
            if ($arrivalTime !== '') {
                $bodyParts[] = "Arrival: {$arrivalTime}";
            }
            if ($status !== null) {
                $bodyParts[] = "Status: {$status}";
            }

            $postedAt = trim((string) ($saag['PostedDateTime'] ?? ''));

            if ($postedAt === '') {
                continue;
            }

            $alerts[] = [
                'external_id' => $externalId,
                'alert_type' => 'saag',
                'service_mode' => 'GO Train',
                'corridor_or_route' => $name ?: $code,
                'corridor_code' => $code ?: null,
                'sub_category' => null,
                'message_subject' => $subject,
                'message_body' => $bodyParts !== [] ? implode('. ', $bodyParts) : null,
                'direction' => $direction,
                'trip_number' => $tripNumber,
                'delay_duration' => $delayDuration,
                'status' => $status,
                'line_colour' => $lineColour,
                'posted_at' => $postedAt,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $entity
     */
    private function extractLineColour(array $entity): ?string
    {
        $colour = trim((string) ($entity['LineColour'] ?? ''));

        return $colour !== '' ? $colour : null;
    }
}
