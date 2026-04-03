<?php

namespace App\Services;

use App\Models\YrtAlert;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class YrtServiceAdvisoriesFeedService
{
    protected const FEED_NAME = 'yrt';

    protected const LIST_URL = 'https://www.yrt.ca/Modules/NewsModule/services/getServiceAdvisories.ashx?categories=b8f1acba-f043-ec11-9468-0050569c41bf&lang=en';

    public function __construct(
        protected FeedCircuitBreaker $circuitBreaker,
    ) {}

    /**
     * @return array{updated_at: CarbonInterface, alerts: list<array<string, mixed>>}
     */
    public function fetch(): array
    {
        $allowEmptyFeeds = (bool) config('feeds.allow_empty_feeds');
        $this->circuitBreaker->throwIfOpen(self::FEED_NAME);

        try {
            $response = $this->fetchListResponse();

            if ($response->failed()) {
                throw new RuntimeException('YRT advisories feed request failed: '.$response->status());
            }

            $items = $response->json();

            if (! is_array($items) || ! array_is_list($items)) {
                throw new RuntimeException('YRT advisories feed returned invalid payload');
            }

            $alerts = $this->normalizeListPayload($items);

            if ($alerts === [] && ! $allowEmptyFeeds) {
                throw new RuntimeException('YRT advisories feed returned zero alerts');
            }

            $result = [
                'updated_at' => Carbon::now()->utc(),
                'alerts' => $alerts,
            ];

            $this->circuitBreaker->recordSuccess(self::FEED_NAME);

            return $result;
        } catch (Throwable $exception) {
            $this->circuitBreaker->recordFailure(self::FEED_NAME, $exception);
            throw $exception;
        }
    }

    protected function httpClient(): PendingRequest
    {
        return Http::connectTimeout(5)
            ->timeout(15)
            ->retry(2, 200, throw: false)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36',
                'Accept-Language' => 'en-CA,en;q=0.9',
                'Referer' => 'https://www.yrt.ca/',
            ]);
    }

    protected function fetchListResponse(): \Illuminate\Http\Client\Response
    {
        try {
            return $this->httpClient()
                ->acceptJson()
                ->get(self::LIST_URL);
        } catch (Throwable $exception) {
            throw new RuntimeException('YRT advisories feed request failed', 0, $exception);
        }
    }

    /**
     * @param  list<mixed>  $items
     * @return list<array<string, mixed>>
     */
    protected function normalizeListPayload(array $items): array
    {
        $normalized = [];

        foreach (array_slice($items, 0, $this->maxRecords()) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $alert = $this->normalizeListItem($item);

            if ($alert !== null) {
                $normalized[] = $alert;
            }
        }

        if ($normalized === []) {
            return [];
        }

        $existingByExternalId = YrtAlert::query()
            ->whereIn('external_id', array_column($normalized, 'external_id'))
            ->get()
            ->keyBy('external_id');

        $now = Carbon::now()->utc();

        foreach ($normalized as &$alert) {
            $existing = $existingByExternalId->get($alert['external_id']);

            if ($this->shouldFetchDetails($alert, $existing, $now)) {
                $detailBodyText = $this->fetchDetailBodyText($alert['details_url']);

                if ($detailBodyText === null) {
                    $alert['body_text'] = $existing?->body_text;
                    $alert['details_fetched_at'] = $existing?->details_fetched_at;
                } else {
                    $alert['body_text'] = $detailBodyText;
                    $alert['details_fetched_at'] = $now;
                }

                if ($alert['route_text'] === null) {
                    $alert['route_text'] = $this->extractRouteFromText($alert['body_text']);
                }

                continue;
            }

            $alert['body_text'] = $existing?->body_text;
            $alert['details_fetched_at'] = $existing?->details_fetched_at;

            if ($alert['route_text'] === null) {
                $alert['route_text'] = $existing?->route_text;
            }
        }
        unset($alert);

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    protected function normalizeListItem(array $item): ?array
    {
        $title = $this->normalizeText($item['title'] ?? null);
        $detailsUrl = $this->normalizeUrl($item['link'] ?? null);
        $postedAt = $this->parsePostedAt($item['postedDate'] ?? null, $item['postedTime'] ?? null);

        if ($title === null || $detailsUrl === null || $postedAt === null) {
            return null;
        }

        $externalId = $this->extractExternalIdFromUrl($detailsUrl);

        if ($externalId === null) {
            return null;
        }

        $descriptionExcerpt = $this->normalizeText($item['description'] ?? null);
        $postedDate = trim((string) ($item['postedDate'] ?? ''));
        $postedTime = trim((string) ($item['postedTime'] ?? ''));

        return [
            'external_id' => $externalId,
            'title' => $title,
            'posted_at' => $postedAt,
            'details_url' => $detailsUrl,
            'description_excerpt' => $descriptionExcerpt,
            'route_text' => $this->extractRouteFromTitle($title) ?? $this->extractRouteFromText($descriptionExcerpt),
            'body_text' => null,
            'list_hash' => sha1(implode('|', [
                $title,
                $descriptionExcerpt ?? '',
                $postedDate,
                $postedTime,
                $detailsUrl,
            ])),
            'details_fetched_at' => null,
            'is_active' => true,
        ];
    }

    protected function shouldFetchDetails(array $normalizedAlert, ?YrtAlert $existingAlert, CarbonInterface $now): bool
    {
        if ($existingAlert === null) {
            return true;
        }

        if ($existingAlert->list_hash !== $normalizedAlert['list_hash']) {
            return true;
        }

        if ($this->normalizeText($existingAlert->body_text) === null) {
            return true;
        }

        if ($existingAlert->details_fetched_at === null) {
            return true;
        }

        return $existingAlert->details_fetched_at->lessThan($now->copy()->subHours($this->detailsRefreshHours()));
    }

    protected function fetchDetailBodyText(string $detailsUrl): ?string
    {
        try {
            $response = $this->httpClient()->get($detailsUrl);

            if ($response->failed()) {
                return null;
            }

            return $this->extractBodyTextFromHtml($response->body());
        } catch (Throwable) {
            return null;
        }
    }

    protected function extractBodyTextFromHtml(string $html): ?string
    {
        if (trim($html) === '') {
            return null;
        }

        $useInternalErrors = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument;

            if (! @$document->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET)) {
                return null;
            }

            $xpath = new DOMXPath($document);

            foreach ($xpath->query('//script|//style|//noscript') ?: [] as $node) {
                $node->parentNode?->removeChild($node);
            }

            // YRT uses div.ge-content for the actual article body — prefer it
            // over <main>/<article> which include nav, footer, and sidebar noise.
            $articleContent = $xpath->query('//div[contains(@class, "ge-content")]');

            if ($articleContent !== false && $articleContent->length > 0) {
                $text = $this->normalizeText($articleContent->item(0)->textContent);

                if ($text !== null) {
                    return $text;
                }
            }

            // Fallback: largest content among semantic containers
            $candidates = $xpath->query('//main|//article|//body');
            $bestText = null;

            if ($candidates !== false) {
                foreach ($candidates as $node) {
                    $text = $this->normalizeText($node->textContent);

                    if ($text === null) {
                        continue;
                    }

                    if ($bestText === null || strlen($text) > strlen($bestText)) {
                        $bestText = $text;
                    }
                }
            }

            if ($bestText !== null) {
                return $bestText;
            }

            return $this->normalizeText($document->textContent);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($useInternalErrors);
        }
    }

    protected function extractExternalIdFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $basename = basename($path);
        $slug = preg_replace('/\.aspx$/i', '', $basename);
        $slug = is_string($slug) ? trim($slug) : '';

        return $slug !== '' ? $slug : null;
    }

    protected function parsePostedAt(mixed $postedDate, mixed $postedTime): ?CarbonInterface
    {
        if (! is_scalar($postedDate) || ! is_scalar($postedTime)) {
            return null;
        }

        $dateValue = trim((string) $postedDate);
        $timeValue = trim((string) $postedTime);

        if ($dateValue === '' || $timeValue === '') {
            return null;
        }

        try {
            return Carbon::parse($dateValue.' '.$timeValue, 'America/Toronto')->utc();
        } catch (Throwable) {
            return null;
        }
    }

    protected function extractRouteFromTitle(string $title): ?string
    {
        if (preg_match('/^\s*([0-9]{1,3}\s*[-–]\s*[^\n\r\|]+)/u', $title, $matches) !== 1) {
            return null;
        }

        return $this->normalizeText($matches[1] ?? null);
    }

    protected function extractRouteFromText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        if (preg_match('/\bRoutes?\s+affected\s*:\s*([^\n\r]+)/iu', $text, $matches) !== 1) {
            return null;
        }

        $segment = trim((string) ($matches[1] ?? ''));
        $segment = preg_split('/[.;|]/', $segment, 2)[0] ?? '';

        return $this->normalizeText($segment);
    }

    protected function normalizeText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = is_string($text) ? trim($text) : '';

        return $text !== '' ? $text : null;
    }

    protected function normalizeUrl(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $url = trim((string) $value);

        if ($url === '') {
            return null;
        }

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    protected function detailsRefreshHours(): int
    {
        return max(1, (int) config('feeds.yrt.details_refresh_hours', 24));
    }

    protected function maxRecords(): int
    {
        return max(1, (int) config('feeds.yrt.max_records', 200));
    }
}
