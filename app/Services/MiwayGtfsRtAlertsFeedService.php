<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Google\Transit\Realtime\Alert;
use Google\Transit\Realtime\FeedMessage;
use Google\Transit\Realtime\TranslatedString;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class MiwayGtfsRtAlertsFeedService
{
    protected const FEED_URL = 'https://www.miapp.ca/gtfs_rt/Alerts/Alerts.pb';

    public function __construct(
        protected FeedCircuitBreaker $circuitBreaker,
    ) {}

    /**
     * @return array{updated_at: CarbonInterface, alerts: list<array<string, mixed>>, not_modified?: true}
     */
    public function fetch(?string $etag = null, ?string $lastModified = null): array
    {
        $allowEmptyFeeds = (bool) config('feeds.allow_empty_feeds');
        $this->circuitBreaker->throwIfOpen('miway_alerts');

        try {
            $request = $this->httpClient();

            if ($etag) {
                $request->withHeader('If-None-Match', $etag);
            }
            if ($lastModified) {
                $request->withHeader('If-Modified-Since', $lastModified);
            }

            $response = $request->get(self::FEED_URL);

            if ($response->status() === 304) {
                $this->circuitBreaker->recordSuccess('miway_alerts');

                return ['updated_at' => Carbon::now()->utc(), 'alerts' => [], 'not_modified' => true];
            }

            if ($response->failed()) {
                throw new RuntimeException('MiWay GTFS-RT feed request failed: '.$response->status());
            }

            $body = $response->body();

            if ($body === '') {
                if (! $allowEmptyFeeds) {
                    throw new RuntimeException('MiWay GTFS-RT feed returned empty payload');
                }
                $this->circuitBreaker->recordSuccess('miway_alerts');

                return ['updated_at' => Carbon::now()->utc(), 'alerts' => []];
            }

            try {
                $feed = new FeedMessage;
                $feed->mergeFromString($body);
            } catch (Throwable $exception) {
                throw new RuntimeException('Failed to decode MiWay GTFS-RT protobuf payload', 0, $exception);
            }

            $header = $feed->getHeader();
            $updatedAt = $header->getTimestamp() > 0
                ? Carbon::createFromTimestamp($header->getTimestamp())->utc()
                : Carbon::now()->utc();

            $alerts = [];
            foreach ($feed->getEntity() as $entity) {
                if (! $entity->getAlert()) {
                    continue;
                }

                $alert = $this->normalizeAlert($entity->getId(), $entity->getAlert());
                if ($alert !== null) {
                    $alerts[] = $alert;
                }
            }

            if ($alerts === [] && ! $allowEmptyFeeds) {
                throw new RuntimeException('MiWay GTFS-RT feed returned zero alerts');
            }

            $this->circuitBreaker->recordSuccess('miway_alerts');

            return [
                'updated_at' => $updatedAt,
                'alerts' => $alerts,
            ];
        } catch (Throwable $exception) {
            $this->circuitBreaker->recordFailure('miway_alerts', $exception);
            throw $exception;
        }
    }

    protected function httpClient(): PendingRequest
    {
        return Http::timeout(15)
            ->retry(2, 200, throw: false);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function normalizeAlert(string $id, Alert $alert): ?array
    {
        $externalId = trim($id);
        if ($externalId === '') {
            return null;
        }

        $headerText = $this->extractTranslation($alert->getHeaderText());
        if ($headerText === null) {
            return null; // Missing required text
        }

        $descriptionText = $this->extractTranslation($alert->getDescriptionText());
        $url = $this->extractTranslation($alert->getUrl());

        $cause = 'UNKNOWN_CAUSE';
        $causeValue = $alert->getCause();
        if ($causeValue !== 0) {
            try {
                $cause = Alert\Cause::name($causeValue);
            } catch (UnexpectedValueException) {
                $cause = 'UNKNOWN_CAUSE';
            }
        }

        $effect = 'UNKNOWN_EFFECT';
        $effectValue = $alert->getEffect();
        if ($effectValue !== 0) {
            try {
                $effect = Alert\Effect::name($effectValue);
            } catch (UnexpectedValueException) {
                $effect = 'UNKNOWN_EFFECT';
            }
        }

        [$startsAt, $endsAt] = $this->extractActivePeriod($alert);

        $detourPdfUrl = null;
        if (is_string($url) && str_ends_with(strtolower($url), '.pdf')) {
            $detourPdfUrl = $url;
        }

        return [
            'external_id' => $externalId,
            'header_text' => $headerText,
            'description_text' => $descriptionText,
            'cause' => $cause,
            'effect' => $effect,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'url' => $url,
            'detour_pdf_url' => $detourPdfUrl,
        ];
    }

    /**
     * @return array{0: ?CarbonInterface, 1: ?CarbonInterface}
     */
    protected function extractActivePeriod(Alert $alert): array
    {
        $startsAt = null;
        $endsAt = null;

        $periods = $alert->getActivePeriod();
        if (count($periods) > 0) {
            $period = $periods[0];
            if ($period->getStart() > 0) {
                $startsAt = Carbon::createFromTimestamp($period->getStart())->utc();
            }
            if ($period->getEnd() > 0) {
                $endsAt = Carbon::createFromTimestamp($period->getEnd())->utc();
            }
        }

        return [$startsAt, $endsAt];
    }

    protected function extractTranslation(?TranslatedString $translatedString, string $preferredLanguage = 'en'): ?string
    {
        if ($translatedString === null) {
            return null;
        }

        $translations = $translatedString->getTranslation();
        if (count($translations) === 0) {
            return null;
        }

        $fallback = null;

        foreach ($translations as $translation) {
            $lang = strtolower($translation->getLanguage());
            $text = trim($translation->getText());

            if ($text === '') {
                continue;
            }

            if ($fallback === null) {
                $fallback = $text;
            }

            if (str_starts_with($lang, $preferredLanguage)) {
                return $text;
            }
        }

        return $fallback;
    }
}
