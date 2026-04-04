<?php

namespace App\Services;

use App\Models\DrtAlert;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class DrtServiceAlertsFeedService
{
    protected const FEED_NAME = 'drt';

    protected const LIST_URL = 'https://www.durhamregiontransit.com/Modules/News/en/ServiceAlertsandDetours';

    protected const CANONICAL_HOST = 'www.durhamregiontransit.com';

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
            $alerts = $this->fetchAndNormalizeListPages();

            if ($alerts === [] && ! $allowEmptyFeeds) {
                throw new RuntimeException('DRT service alerts feed returned zero alerts');
            }

            $this->circuitBreaker->recordSuccess(self::FEED_NAME);

            return [
                'updated_at' => Carbon::now()->utc(),
                'alerts' => $alerts,
            ];
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
                'Referer' => 'https://www.durhamregiontransit.com/',
            ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function fetchAndNormalizeListPages(): array
    {
        $currentPage = 1;
        $normalizedByExternalId = [];

        do {
            $response = $this->fetchListResponse($currentPage);

            if ($response->failed()) {
                throw new RuntimeException('DRT service alerts feed request failed: '.$response->status());
            }

            $pageAlerts = $this->parseListPageHtml($response->body());

            foreach ($pageAlerts as $alert) {
                $normalizedByExternalId[$alert['external_id']] = $alert;
            }

            $currentPage++;

            if ($currentPage > $this->maxPages()) {
                break;
            }

            $nextPage = $this->extractNextPageNumber($response->body(), $currentPage - 1);
        } while ($nextPage !== null && $nextPage === $currentPage);

        return $this->hydrateDetails(array_values($normalizedByExternalId));
    }

    protected function fetchListResponse(int $page): Response
    {
        try {
            $url = $page > 1 ? self::LIST_URL.'?page='.$page : self::LIST_URL;

            return $this->httpClient()->get($url);
        } catch (Throwable $exception) {
            throw new RuntimeException('DRT service alerts feed request failed', 0, $exception);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function parseListPageHtml(string $html): array
    {
        $document = $this->loadDomDocument($html);

        if ($document === null) {
            return [];
        }

        $xpath = new DOMXPath($document);
        $alerts = [];
        $seenDetailsUrls = [];

        $links = $xpath->query('//a[contains(@href, "/en/news/") and contains(@href, ".aspx")]');

        if ($links === false) {
            return [];
        }

        foreach ($links as $linkNode) {
            if (! $linkNode instanceof DOMElement) {
                continue;
            }

            $detailsUrl = $this->normalizeDetailUrl($linkNode->getAttribute('href'));

            if ($detailsUrl === null || isset($seenDetailsUrls[$detailsUrl])) {
                continue;
            }

            $title = $this->normalizeText($linkNode->textContent);

            if ($title === null) {
                continue;
            }

            $contextNode = $this->findListContextNode($linkNode);

            if ($contextNode === null) {
                continue;
            }

            $contextText = $this->normalizeText($contextNode->textContent);

            if ($contextText === null) {
                continue;
            }

            $postedOnLine = $this->extractPostedOnLine($contextText);
            $postedAt = $this->parsePostedAt($postedOnLine);
            $externalId = $this->extractExternalIdFromUrl($detailsUrl);

            if ($postedOnLine === null || $postedAt === null || $externalId === null) {
                continue;
            }

            $whenText = $this->extractLabelValueFromContextNode($contextNode, 'When')
                ?? $this->extractLabelValue($contextText, 'When');
            $routeText = $this->extractLabelValueFromContextNode($contextNode, 'Route')
                ?? $this->extractLabelValueFromContextNode($contextNode, 'Routes')
                ?? $this->extractLabelValue($contextText, 'Route')
                ?? $this->extractLabelValue($contextText, 'Routes');
            $descriptionExcerpt = $this->extractDescriptionExcerpt($contextText, $title, $postedOnLine, $whenText, $routeText);

            $alerts[] = [
                'external_id' => $externalId,
                'title' => $title,
                'posted_at' => $postedAt,
                'when_text' => $whenText,
                'route_text' => $routeText,
                'details_url' => $detailsUrl,
                'description_excerpt' => $descriptionExcerpt,
                'body_text' => null,
                'list_hash' => $this->buildListHash(
                    title: $title,
                    postedOnLine: $postedOnLine,
                    whenText: $whenText,
                    routeText: $routeText,
                    excerpt: $descriptionExcerpt,
                    detailsUrl: $detailsUrl,
                ),
                'details_fetched_at' => null,
                'is_active' => true,
            ];

            $seenDetailsUrls[$detailsUrl] = true;
        }

        return $alerts;
    }

    protected function extractNextPageNumber(string $html, int $currentPage): ?int
    {
        preg_match_all('/ServiceAlertsandDetours\?page=(\d+)/i', $html, $matches);
        $candidates = array_map('intval', $matches[1] ?? []);
        $candidates = array_values(array_filter($candidates, fn (int $page): bool => $page > $currentPage));

        if ($candidates === []) {
            return null;
        }

        sort($candidates);

        return $candidates[0];
    }

    /**
     * @param  list<array<string, mixed>>  $alerts
     * @return list<array<string, mixed>>
     */
    protected function hydrateDetails(array $alerts): array
    {
        if ($alerts === []) {
            return [];
        }

        $existingByExternalId = DrtAlert::query()
            ->whereIn('external_id', array_column($alerts, 'external_id'))
            ->get()
            ->keyBy('external_id');

        $now = Carbon::now()->utc();

        foreach ($alerts as &$alert) {
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

                continue;
            }

            $alert['body_text'] = $existing?->body_text;
            $alert['details_fetched_at'] = $existing?->details_fetched_at;
        }
        unset($alert);

        return $alerts;
    }

    /**
     * @param  array<string, mixed>  $normalizedAlert
     */
    protected function shouldFetchDetails(array $normalizedAlert, ?DrtAlert $existingAlert, CarbonInterface $now): bool
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

            return $this->extractDetailBodyTextFromHtml($response->body());
        } catch (Throwable) {
            return null;
        }
    }

    protected function extractDetailBodyTextFromHtml(string $html): ?string
    {
        $document = $this->loadDomDocument($html);

        if ($document === null) {
            return null;
        }

        $xpath = new DOMXPath($document);

        foreach ($xpath->query('//script|//style|//noscript') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }

        $tokenContent = $xpath->query('//div[contains(@class, "iCreateDynaToken")]');

        if ($tokenContent !== false && $tokenContent->length > 0) {
            $tokenText = $this->normalizeText($tokenContent->item(0)?->textContent);

            if ($tokenText !== null) {
                return $tokenText;
            }
        }

        $fullText = $this->normalizeText($document->textContent);

        if ($fullText !== null) {
            $betweenBoundaries = $this->extractTextBetweenMarkers($fullText, 'Back to Search', 'Subscribe');

            if ($betweenBoundaries !== null) {
                return $betweenBoundaries;
            }
        }

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

        return $bestText;
    }

    protected function normalizeDetailUrl(?string $url): ?string
    {
        $value = $this->normalizeText($url);

        if ($value === null) {
            return null;
        }

        if (! str_starts_with(strtolower($value), 'http')) {
            if (! str_starts_with($value, '/')) {
                $value = '/'.$value;
            }

            $value = 'https://'.self::CANONICAL_HOST.$value;
        }

        $parts = parse_url($value);

        if (! is_array($parts) || ! isset($parts['path'])) {
            return null;
        }

        if (! str_contains(strtolower($parts['path']), '/en/news/') || ! str_ends_with(strtolower($parts['path']), '.aspx')) {
            return null;
        }

        $path = '/'.ltrim($parts['path'], '/');
        $query = isset($parts['query']) && trim($parts['query']) !== '' ? '?'.$parts['query'] : '';

        return 'https://'.self::CANONICAL_HOST.$path.$query;
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

    protected function parsePostedAt(?string $postedOnLine): ?CarbonInterface
    {
        $value = $this->normalizeText($postedOnLine);

        if ($value === null) {
            return null;
        }

        $value = preg_replace('/^Posted\s+on\s+/i', '', $value);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        foreach (['l, F d, Y h:i A', 'l, F j, Y h:i A'] as $format) {
            try {
                return Carbon::createFromFormat($format, trim($value), 'America/Toronto')->utc();
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    protected function extractPostedOnLine(string $contextText): ?string
    {
        preg_match('/Posted\s+on\s+[A-Za-z]+,\s+[A-Za-z]+\s+\d{1,2},\s+\d{4}\s+\d{1,2}:\d{2}\s*(?:AM|PM)/i', $contextText, $matches);

        return isset($matches[0]) ? $this->normalizeText($matches[0]) : null;
    }

    protected function extractLabelValue(string $contextText, string $label): ?string
    {
        $pattern = '/\b'.preg_quote($label, '/').'\s*:\s*(.+?)(?=\bWhen\s*:|\bRoute(?:s)?\s*:|\bRead\s+more\b|$)/is';

        if (preg_match($pattern, $contextText, $matches) !== 1) {
            return null;
        }

        return $this->normalizeText($matches[1] ?? null);
    }

    protected function extractLabelValueFromContextNode(DOMNode $contextNode, string $label): ?string
    {
        $ownerDocument = $contextNode->ownerDocument;

        if (! $ownerDocument instanceof DOMDocument) {
            return null;
        }

        $xpath = new DOMXPath($ownerDocument);
        $nodes = $xpath->query('.//p', $contextNode);

        if ($nodes === false) {
            return null;
        }

        foreach ($nodes as $node) {
            $line = $this->normalizeText($node->textContent);

            if ($line === null) {
                continue;
            }

            if (preg_match('/^'.preg_quote($label, '/').'\s*:\s*(.+)$/i', $line, $matches) !== 1) {
                continue;
            }

            return $this->normalizeText($matches[1] ?? null);
        }

        return null;
    }

    protected function extractDescriptionExcerpt(
        string $contextText,
        string $title,
        string $postedOnLine,
        ?string $whenText,
        ?string $routeText,
    ): ?string {
        $excerpt = $contextText;
        $removals = [
            $title,
            $postedOnLine,
            $whenText !== null ? 'When: '.$whenText : null,
            $routeText !== null ? 'Route: '.$routeText : null,
            $routeText !== null ? 'Routes: '.$routeText : null,
            'Read more',
            'Service Alerts and Detours',
        ];

        foreach ($removals as $removeValue) {
            $normalizedRemoveValue = $this->normalizeText($removeValue);

            if ($normalizedRemoveValue === null) {
                continue;
            }

            $excerpt = str_ireplace($normalizedRemoveValue, '', $excerpt);
        }

        return $this->normalizeText($excerpt);
    }

    protected function extractTextBetweenMarkers(string $text, string $startMarker, string $endMarker): ?string
    {
        $startPosition = stripos($text, $startMarker);
        $endPosition = stripos($text, $endMarker);

        if ($startPosition === false || $endPosition === false || $endPosition <= $startPosition) {
            return null;
        }

        $startPosition += strlen($startMarker);
        $slice = substr($text, $startPosition, $endPosition - $startPosition);

        return $this->normalizeText($slice);
    }

    protected function normalizeText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = (string) $value;
        $normalized = str_replace(
            ['â', 'â', 'â', 'â¢', 'â', 'â', 'â¦'],
            ['’', '–', '—', '•', '“', '”', '…'],
            $normalized,
        );
        $normalized = str_replace("\xc2\xa0", ' ', $normalized);
        $normalized = str_replace("\u{00A0}", ' ', $normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized);
        $normalized = is_string($normalized) ? trim($normalized) : '';

        return $normalized !== '' ? $normalized : null;
    }

    protected function findListContextNode(DOMNode $node): ?DOMNode
    {
        $cursor = $node;
        $maxDepth = 8;

        for ($depth = 0; $depth < $maxDepth; $depth++) {
            $cursor = $cursor->parentNode;

            if ($cursor === null) {
                return null;
            }

            $text = $this->normalizeText($cursor->textContent);

            if ($text !== null && str_contains($text, 'Posted on')) {
                return $cursor;
            }
        }

        return null;
    }

    protected function buildListHash(
        string $title,
        string $postedOnLine,
        ?string $whenText,
        ?string $routeText,
        ?string $excerpt,
        string $detailsUrl,
    ): string {
        return sha1(implode('|', [
            $title,
            $postedOnLine,
            $whenText ?? '',
            $routeText ?? '',
            $excerpt ?? '',
            $detailsUrl,
        ]));
    }

    protected function detailsRefreshHours(): int
    {
        $value = (int) config('feeds.drt.details_refresh_hours', 24);

        return max(1, $value);
    }

    protected function maxPages(): int
    {
        $value = (int) config('feeds.drt.max_pages', 10);

        return max(1, $value);
    }

    protected function loadDomDocument(string $html): ?DOMDocument
    {
        if (trim($html) === '') {
            return null;
        }

        $useInternalErrors = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument;
            $normalizedHtml = '<?xml encoding="UTF-8">'.$html;

            if (! @$document->loadHTML($normalizedHtml, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET)) {
                return null;
            }

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($useInternalErrors);
        }
    }
}
