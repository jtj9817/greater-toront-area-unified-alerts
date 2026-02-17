<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TtcAlertsFeedService
{
    protected const LIVE_ALERTS_URL = 'https://alerts.ttc.ca/api/alerts/live-alerts';

    protected const SXA_RESULTS_URL = 'https://www.ttc.ca//sxa/search/results/';

    protected const STATIC_STREETCAR_URL = 'https://www.ttc.ca/service-advisories/Streetcar-Service-Changes';

    protected const SXA_ENDPOINTS = [
        ['scope' => '{F79E7245-3705-4E03-827E-02569508B481}', 'itemid' => '{B3DD22A4-3F53-4470-A87A-37A77976B07F}'],
        ['scope' => '{99D7699F-DB47-4BB1-8946-77561CE7B320}', 'itemid' => '{72CC555F-9128-4581-AD12-3D04AB1C87BA}'],
        ['scope' => '{FB2F6677-50FB-4294-9A0B-34DD78C8EF45}', 'itemid' => '{55AF6373-A0DF-4781-8282-DCAFFF6FA53E}'],
        ['scope' => '{2EF860AF-9B7D-4460-8281-D428D8E09DC4}', 'itemid' => '{AE874E1E-461E-4EF2-BB4F-8C5A50B6C825}'],
    ];

    /**
     * @return array{updated_at: CarbonInterface, alerts: list<array<string, mixed>>}
     */
    public function fetch(): array
    {
        $allowEmptyFeeds = (bool) config('feeds.allow_empty_feeds');
        [$updatedAt, $primaryAlerts] = $this->fetchLiveApiAlerts();

        $alerts = $primaryAlerts;
        $alerts = array_merge($alerts, $this->fetchSxaAlerts());
        $alerts = array_merge($alerts, $this->fetchStaticAlerts());

        $dedupedAlerts = array_values($this->dedupeByExternalId($alerts));

        if ($dedupedAlerts === [] && ! $allowEmptyFeeds) {
            throw new RuntimeException('TTC alerts feed returned zero alerts');
        }

        return [
            'updated_at' => $updatedAt,
            'alerts' => $dedupedAlerts,
        ];
    }

    /**
     * @return array{0: CarbonInterface, 1: list<array<string, mixed>>}
     */
    protected function fetchLiveApiAlerts(): array
    {
        $response = $this->httpClient()
            ->acceptJson()
            ->get(self::LIVE_ALERTS_URL);

        if ($response->failed()) {
            throw new RuntimeException('TTC live alerts request failed: '.$response->status());
        }

        $data = $response->json();

        if (! is_array($data) || ! isset($data['lastUpdated'])) {
            throw new RuntimeException("TTC live alerts response missing required 'lastUpdated' field");
        }

        $updatedAt = $this->parseIsoTimestamp($data['lastUpdated'], true);

        $alerts = [];
        $buckets = ['routes', 'accessibility', 'siteWideCustom', 'generalCustom', 'stops'];

        foreach ($buckets as $bucket) {
            $records = $data[$bucket] ?? [];
            if (! is_array($records)) {
                continue;
            }

            foreach ($records as $record) {
                if (! is_array($record)) {
                    continue;
                }

                $normalized = $this->normalizeLiveApiAlert($record, $bucket);
                if ($normalized !== null) {
                    $alerts[] = $normalized;
                }
            }
        }

        return [$updatedAt, $alerts];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function fetchSxaAlerts(): array
    {
        $alerts = [];

        foreach (self::SXA_ENDPOINTS as $endpoint) {
            try {
                $response = $this->httpClient()
                    ->acceptJson()
                    ->get(self::SXA_RESULTS_URL, [
                        's' => $endpoint['scope'],
                        'itemid' => $endpoint['itemid'],
                        'sig' => '',
                        'autoFireSearch' => 'true',
                        'p' => 10,
                        'o' => 'EffectiveStartDate,Ascending',
                    ]);

                if ($response->failed()) {
                    throw new RuntimeException('request failed: '.$response->status());
                }

                $payload = $response->json();
                $results = $payload['Results'] ?? null;

                if (! is_array($results)) {
                    throw new RuntimeException("response missing required 'Results' field");
                }

                foreach ($results as $result) {
                    if (! is_array($result)) {
                        continue;
                    }

                    $normalized = $this->normalizeSxaAlert($result);
                    if ($normalized !== null) {
                        $alerts[] = $normalized;
                    }
                }
            } catch (\Throwable $exception) {
                Log::warning('TTC SXA source failed: '.$exception->getMessage());
            }
        }

        return $alerts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function fetchStaticAlerts(): array
    {
        try {
            $response = $this->httpClient()->get(self::STATIC_STREETCAR_URL);

            if ($response->failed()) {
                throw new RuntimeException('request failed: '.$response->status());
            }

            return $this->parseStaticHtml($response->body());
        } catch (\Throwable $exception) {
            Log::warning('TTC static source failed: '.$exception->getMessage());

            return [];
        }
    }

    protected function httpClient(): PendingRequest
    {
        return Http::timeout(15)
            ->retry(2, 200, throw: false)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Referer' => 'https://www.ttc.ca/',
            ]);
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>|null
     */
    protected function normalizeLiveApiAlert(array $record, string $bucket): ?array
    {
        if ($bucket === 'accessibility') {
            return $this->normalizeAccessibilityAlert($record);
        }

        $externalSourceId = $this->stringValue($record['id'] ?? null);
        $title = $this->stringValue($record['title'] ?? $record['headerText'] ?? $record['customHeaderText'] ?? null);

        if ($externalSourceId === null || $title === null) {
            return null;
        }

        $activePeriod = is_array($record['activePeriod'] ?? null) ? $record['activePeriod'] : [];
        $activeStart = $this->parseIsoTimestamp($activePeriod['start'] ?? null, false);
        $activeEnd = $this->parseIsoTimestamp($activePeriod['end'] ?? null, false);

        return [
            'external_id' => 'api:'.$externalSourceId,
            'source_feed' => 'live-api',
            'alert_type' => $this->stringValue($record['alertType'] ?? null),
            'route_type' => $this->stringValue($record['routeType'] ?? null),
            'route' => $this->stringValue($record['route'] ?? null),
            'title' => $title,
            'description' => $this->sanitizeDescription($record['description'] ?? null),
            'severity' => $this->stringValue($record['severity'] ?? null),
            'effect' => $this->stringValue($record['effect'] ?? null),
            'cause' => $this->stringValue($record['causeDescription'] ?? $record['cause'] ?? null),
            'active_period_start' => $activeStart,
            'active_period_end' => $activeEnd,
            'direction' => $this->stringValue($record['direction'] ?? null),
            'stop_start' => $this->stringValue($record['stopStart'] ?? null),
            'stop_end' => $this->stringValue($record['stopEnd'] ?? null),
            'url' => $this->stringValue($record['url'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>|null
     */
    protected function normalizeAccessibilityAlert(array $record): ?array
    {
        $externalSourceId = $this->stringValue($record['id'] ?? null);
        if ($externalSourceId === null) {
            return null;
        }

        $status = $this->extractAccessibilityStatus($record);
        $station = $this->extractAccessibilityStation($record);
        $deviceType = $this->extractAccessibilityDeviceType($record);
        $description = $this->sanitizeDescription($record['description'] ?? $record['detail'] ?? null);

        $activePeriod = is_array($record['activePeriod'] ?? null) ? $record['activePeriod'] : [];
        $activeStart = $this->parseIsoTimestamp($activePeriod['start'] ?? null, false);
        $activeEnd = $this->parseIsoTimestamp($activePeriod['end'] ?? null, false);

        $title = $this->stringValue($record['title'] ?? $record['headerText'] ?? $record['customHeaderText'] ?? null)
            ?? trim(implode(' ', array_filter([
                $station,
                $deviceType !== null ? ucfirst($deviceType) : null,
                $status,
            ])));

        if ($title === '') {
            return null;
        }

        $route = $this->normalizeRouteValue($record['route'] ?? $record['line'] ?? null);

        return [
            'external_id' => 'api:accessibility:'.$externalSourceId,
            'source_feed' => 'ttc_accessibility',
            'alert_type' => 'accessibility',
            'route_type' => $deviceType,
            'route' => $route,
            'title' => $title,
            'description' => $description,
            'severity' => $this->isOutOfServiceStatus($status) ? 'Major' : 'Minor',
            'effect' => $status,
            'cause' => $this->stringValue($record['causeDescription'] ?? $record['cause'] ?? null),
            'active_period_start' => $activeStart,
            'active_period_end' => $activeEnd,
            'direction' => null,
            'stop_start' => $station,
            'stop_end' => null,
            'url' => $this->stringValue($record['url'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>|null
     */
    protected function normalizeSxaAlert(array $result): ?array
    {
        $id = $this->stringValue($result['Id'] ?? null);
        if ($id === null) {
            return null;
        }

        $html = (string) ($result['Html'] ?? '');
        $parsed = $this->parseSxaHtml($html);

        $title = $this->stringValue($parsed['title'] ?? $result['Name'] ?? null);
        if ($title === null) {
            return null;
        }

        $url = $this->normalizeTtcUrl($this->stringValue($result['Url'] ?? null));

        return [
            'external_id' => 'sxa:'.$id,
            'source_feed' => 'sxa',
            'alert_type' => 'Planned',
            'route_type' => null,
            'route' => $this->normalizeRouteValue($parsed['route'] ?? null),
            'title' => $title,
            'description' => null,
            'severity' => null,
            'effect' => null,
            'cause' => null,
            'active_period_start' => $this->parseTorontoTimestamp($parsed['start'] ?? null),
            'active_period_end' => $this->parseTorontoTimestamp($parsed['end'] ?? null),
            'direction' => null,
            'stop_start' => null,
            'stop_end' => null,
            'url' => $url,
        ];
    }

    /**
     * @return array{title: ?string, route: ?string, start: ?string, end: ?string}
     */
    protected function parseSxaHtml(string $html): array
    {
        if (trim($html) === '') {
            return ['title' => null, 'route' => null, 'start' => null, 'end' => null];
        }

        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $document->loadHTML('<html><body>'.$html.'</body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($document);

        return [
            'title' => $this->xpathText($xpath, "//*[contains(concat(' ', normalize-space(@class), ' '), ' field-satitle ')]"),
            'route' => $this->xpathText($xpath, "//*[contains(concat(' ', normalize-space(@class), ' '), ' field-route ')]"),
            'start' => $this->xpathText($xpath, "//*[contains(concat(' ', normalize-space(@class), ' '), ' field-starteffectivedate ')]"),
            'end' => $this->xpathText($xpath, "//*[contains(concat(' ', normalize-space(@class), ' '), ' field-endeffectivedate ')]"),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function parseStaticHtml(string $html): array
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($document);
        $containers = $xpath->query(
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' streetcar-advisory ') 
                or contains(concat(' ', normalize-space(@class), ' '), ' service-advisory ')
                or contains(concat(' ', normalize-space(@class), ' '), ' service-advisories ')
                or contains(concat(' ', normalize-space(@class), ' '), ' advisory-item ')
                or contains(concat(' ', normalize-space(@class), ' '), ' accordion-item ')]"
        );

        if ($containers === false) {
            throw new RuntimeException('failed to parse static advisory HTML');
        }

        $alerts = [];

        foreach ($containers as $container) {
            $title = $this->stringValue($xpath->evaluate('string(.//h1|.//h2|.//h3|.//h4)', $container));
            if ($title === null) {
                continue;
            }

            $description = $this->stringValue($xpath->evaluate('string(.//p[1])', $container));
            $rawRoute = $this->extractRouteFromTitle($title);
            $route = $this->normalizeRouteValue($rawRoute);
            $relativeUrl = $this->stringValue($xpath->evaluate('string(.//a[1]/@href)', $container));
            $url = $this->normalizeTtcUrl($relativeUrl);

            $externalHash = md5(strtolower($title.'|'.($route ?? '').'|'.($url ?? '')));

            $alerts[] = [
                'external_id' => 'static:'.$externalHash,
                'source_feed' => 'static',
                'alert_type' => 'Planned',
                'route_type' => 'Streetcar',
                'route' => $route,
                'title' => $title,
                'description' => $description,
                'severity' => null,
                'effect' => null,
                'cause' => null,
                'active_period_start' => null,
                'active_period_end' => null,
                'direction' => null,
                'stop_start' => null,
                'stop_end' => null,
                'url' => $url,
            ];
        }

        return $alerts;
    }

    protected function parseIsoTimestamp(mixed $value, bool $strict): ?CarbonInterface
    {
        $raw = $this->stringValue($value);

        if ($raw === null) {
            if ($strict) {
                throw new RuntimeException('missing required ISO8601 timestamp');
            }

            return null;
        }

        if (str_starts_with($raw, '0001-01-01T00:00:00')) {
            return null;
        }

        try {
            return Carbon::parse($raw, 'UTC')->utc();
        } catch (\Throwable $exception) {
            if (! $strict) {
                return null;
            }

            throw new RuntimeException("invalid ISO8601 timestamp '{$raw}'", 0, $exception);
        }
    }

    protected function parseTorontoTimestamp(mixed $value): ?CarbonInterface
    {
        $raw = $this->stringValue($value);
        if ($raw === null) {
            return null;
        }

        try {
            return Carbon::parse($raw, 'America/Toronto')->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function sanitizeDescription(mixed $value): ?string
    {
        $raw = $this->stringValue($value);
        if ($raw === null) {
            return null;
        }

        $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $stripped = strip_tags($decoded);
        $collapsed = preg_replace('/\s+/', ' ', trim($stripped));

        return $collapsed === '' ? null : $collapsed;
    }

    protected function normalizeRouteValue(mixed $value): ?string
    {
        $route = $this->stringValue($value);
        if ($route === null) {
            return null;
        }

        $route = str_replace('|', ',', $route);
        $route = preg_replace('/\s*,\s*/', ',', $route);
        $route = preg_replace('/\s+/', ' ', $route);

        return $route === '' ? null : $route;
    }

    protected function normalizeTtcUrl(?string $value): ?string
    {
        $url = $this->stringValue($value);
        if ($url === null) {
            return null;
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return 'https://www.ttc.ca/'.ltrim($url, '/');
    }

    protected function extractRouteFromTitle(string $title): ?string
    {
        if (preg_match('/\b(\d{1,3}(?:[\\/,|]\d{1,3})*)\b/', $title, $matches) !== 1) {
            return null;
        }

        return $matches[1] ?? null;
    }

    protected function xpathText(\DOMXPath $xpath, string $expression): ?string
    {
        $value = $xpath->evaluate('string('.$expression.')');

        return $this->stringValue(is_string($value) ? $value : null);
    }

    protected function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function extractAccessibilityStatus(array $record): string
    {
        $rawStatus = $this->stringValue(
            $record['status']
            ?? $record['currentStatus']
            ?? $record['effect']
            ?? $record['deviceStatus']
            ?? null
        );

        if ($rawStatus === null) {
            $fallback = strtolower(implode(' ', array_filter([
                (string) ($record['title'] ?? ''),
                (string) ($record['description'] ?? ''),
            ])));

            if (str_contains($fallback, 'out of service') || str_contains($fallback, 'not in service')) {
                return 'OUT_OF_SERVICE';
            }

            if (str_contains($fallback, 'in service') || str_contains($fallback, 'restored')) {
                return 'IN_SERVICE';
            }

            return 'UNKNOWN';
        }

        $status = strtoupper(str_replace([' ', '-'], '_', $rawStatus));

        if (str_contains($status, 'OUT_OF_SERVICE') || str_contains($status, 'NOT_IN_SERVICE')) {
            return 'OUT_OF_SERVICE';
        }

        if (str_contains($status, 'IN_SERVICE') || str_contains($status, 'RESTORED')) {
            return 'IN_SERVICE';
        }

        return $status;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function extractAccessibilityStation(array $record): ?string
    {
        return $this->stringValue(
            $record['stationName']
            ?? $record['station']
            ?? $record['stopName']
            ?? $record['location']
            ?? $record['stopStart']
            ?? null
        );
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function extractAccessibilityDeviceType(array $record): ?string
    {
        $raw = strtolower(trim((string) (
            $record['deviceType']
            ?? $record['equipmentType']
            ?? $record['device']
            ?? $record['title']
            ?? $record['description']
            ?? ''
        )));

        if ($raw === '') {
            return null;
        }

        if (str_contains($raw, 'escalator')) {
            return 'escalator';
        }

        if (str_contains($raw, 'elevator') || str_contains($raw, 'lift')) {
            return 'elevator';
        }

        return null;
    }

    protected function isOutOfServiceStatus(string $status): bool
    {
        return $status === 'OUT_OF_SERVICE'
            || str_contains($status, 'NOT_IN_SERVICE')
            || str_contains($status, 'UNAVAILABLE');
    }

    /**
     * @param  list<array<string, mixed>>  $alerts
     * @return array<string, array<string, mixed>>
     */
    protected function dedupeByExternalId(array $alerts): array
    {
        $deduped = [];

        foreach ($alerts as $alert) {
            $externalId = $alert['external_id'] ?? null;
            if (! is_string($externalId) || $externalId === '') {
                continue;
            }

            $deduped[$externalId] = $alert;
        }

        return $deduped;
    }
}
