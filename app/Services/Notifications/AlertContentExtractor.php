<?php

namespace App\Services\Notifications;

class AlertContentExtractor
{
    /**
     * @return array<int, string>
     */
    public function extract(NotificationAlert $alert): array
    {
        $urns = [];
        $text = $this->alertText($alert);

        if (in_array($alert->source, ['transit', 'ttc_accessibility'], true)) {
            $urns[] = 'agency:ttc';
        }

        foreach ($this->extractRoutes($alert, $text) as $routeId) {
            $urns[] = 'route:'.$routeId;
        }

        $config = $this->transitData();
        $stationMatches = $this->extractStations($text, is_array($config['stations'] ?? null) ? $config['stations'] : []);
        $urns = [...$urns, ...$stationMatches];

        $lineMatches = $this->extractLines($text, is_array($config['lines'] ?? null) ? $config['lines'] : []);
        $urns = [...$urns, ...$lineMatches];

        return array_values(array_unique(array_filter(
            array_map(
                static fn (string $urn): string => strtolower(trim($urn)),
                $urns,
            ),
            static fn (string $urn): bool => $urn !== '',
        )));
    }

    private function alertText(NotificationAlert $alert): string
    {
        $parts = [
            $alert->summary,
            (string) ($alert->metadata['description'] ?? ''),
            (string) ($alert->metadata['effect'] ?? ''),
            (string) ($alert->metadata['route'] ?? ''),
            (string) ($alert->metadata['route_type'] ?? ''),
            (string) ($alert->metadata['stop_start'] ?? ''),
            (string) ($alert->metadata['stop_end'] ?? ''),
            implode(' ', $alert->routes),
        ];

        return strtolower(implode(' ', array_filter($parts, static fn (string $part): bool => trim($part) !== '')));
    }

    /**
     * @return array<int, string>
     */
    private function extractRoutes(NotificationAlert $alert, string $text): array
    {
        $matched = [];

        foreach ($alert->routes as $route) {
            $normalized = $this->normalizeRouteId($route);
            if ($normalized !== null) {
                $matched[] = $normalized;
            }
        }

        $configuredNumericRouteIds = $this->configuredNumericRouteIds();

        $configuredNumericLookup = array_fill_keys($configuredNumericRouteIds, true);

        // 1. Line X context (safest for single digits)
        preg_match_all('/\bline\s*([0-9])\b/i', $text, $lineMatches);
        foreach ($lineMatches[1] ?? [] as $lineId) {
            $normalized = $this->normalizeRouteId((string) $lineId);
            if ($normalized !== null && ($configuredNumericRouteIds === [] || array_key_exists($normalized, $configuredNumericLookup))) {
                $matched[] = $normalized;
            }
        }

        // 2. Broad regex for standard bus routes and others (1-3 digits).
        // Exclude time-like patterns such as "11:29" (with or without spaces).
        preg_match_all(
            '/\b(50[1-8]|3\d{2}|[1-9]\d{0,2})\b(?!\s*(?:am|pm|min|sec|hour|day|week|month|year)|-)/i',
            $text,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        foreach ($matches[1] ?? [] as $match) {
            $route = is_array($match) ? (string) ($match[0] ?? '') : (string) $match;
            $offset = is_array($match) ? (int) ($match[1] ?? -1) : -1;

            if ($offset >= 0 && $this->isTimeAdjacentToken($text, $route, $offset)) {
                continue;
            }

            $normalized = $this->normalizeRouteId($route);
            if ($normalized === null) {
                continue;
            }

            // Accept if it's in the config OR if config is empty (legacy mode)
            // OR if it's a multi-digit number (standard bus routes are often not all in config)
            // We treat single digits cautiously unless they were matched by "Line X" logic or are in config.
            if ($configuredNumericRouteIds === [] || array_key_exists($normalized, $configuredNumericLookup) || strlen($normalized) >= 2) {
                $matched[] = $normalized;
            }
        }

        return array_values(array_unique($matched));
    }

    private function isTimeAdjacentToken(string $text, string $token, int $offset): bool
    {
        $tokenLen = strlen($token);
        if ($tokenLen === 0) {
            return false;
        }

        // Look left for the nearest non-whitespace character.
        for ($i = $offset - 1; $i >= 0; $i--) {
            $char = $text[$i];
            if (! ctype_space($char)) {
                if ($char === ':') {
                    return true;
                }
                break;
            }
        }

        // Look right for the nearest non-whitespace character.
        $rightStart = $offset + $tokenLen;
        $textLen = strlen($text);
        for ($i = $rightStart; $i < $textLen; $i++) {
            $char = $text[$i];
            if (! ctype_space($char)) {
                return $char === ':';
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function configuredNumericRouteIds(): array
    {
        $config = $this->transitData();
        $routes = is_array($config['routes'] ?? null) ? $config['routes'] : [];

        $ids = [];

        foreach ($routes as $route) {
            if (! is_array($route)) {
                continue;
            }

            $id = $this->normalizeRouteId((string) ($route['id'] ?? ''));
            if ($id === null) {
                continue;
            }

            if (! ctype_digit($id)) {
                continue;
            }

            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<int, mixed>  $stations
     * @return array<int, string>
     */
    private function extractStations(string $text, array $stations): array
    {
        $matches = [];

        foreach ($stations as $station) {
            if (! is_array($station)) {
                continue;
            }

            $slug = trim((string) ($station['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $keywords = array_filter(array_map(
                static fn (mixed $keyword): string => strtolower(trim((string) $keyword)),
                [
                    $station['name'] ?? '',
                    ...(is_array($station['aliases'] ?? null) ? $station['aliases'] : []),
                ],
            ));

            if ($this->containsAnyKeyword($text, $keywords)) {
                $matches[] = 'station:'.$slug;

                foreach ((is_array($station['lines'] ?? null) ? $station['lines'] : []) as $line) {
                    $lineId = trim((string) $line);
                    if ($lineId !== '') {
                        $matches[] = 'line:'.$lineId;
                    }
                }
            }
        }

        return $matches;
    }

    /**
     * @param  array<int, mixed>  $lines
     * @return array<int, string>
     */
    private function extractLines(string $text, array $lines): array
    {
        $matches = [];

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $id = trim((string) ($line['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $keywords = array_filter(array_map(
                static fn (mixed $keyword): string => strtolower(trim((string) $keyword)),
                [
                    $line['name'] ?? '',
                    ...(is_array($line['aliases'] ?? null) ? $line['aliases'] : []),
                    'line '.$id,
                ],
            ));

            if ($this->containsAnyKeyword($text, $keywords)) {
                $matches[] = 'line:'.$id;
            }
        }

        return $matches;
    }

    /**
     * @param  array<int, string>  $keywords
     */
    private function containsAnyKeyword(string $text, array $keywords): bool
    {
        $validKeywords = array_filter($keywords, static fn (string $k): bool => $k !== '');

        if ($validKeywords === []) {
            return false;
        }

        $pattern = '/\b(?:'.implode('|', array_map(
            static fn (string $keyword): string => str_replace('\ ', '\s+', preg_quote($keyword, '/')),
            $validKeywords
        )).')\b/i';

        return preg_match($pattern, $text) === 1;
    }

    private function normalizeRouteId(string $route): ?string
    {
        $normalized = strtolower(trim($route));
        if ($normalized === '') {
            return null;
        }

        return preg_replace('/\s+/', '', $normalized) ?: null;
    }

    /**
     * @return array<string, mixed>
     */
    private function transitData(): array
    {
        try {
            $config = config('transit_data');
        } catch (\Throwable) {
            return [];
        }

        return is_array($config) ? $config : [];
    }
}
