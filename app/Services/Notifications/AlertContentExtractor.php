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

        preg_match_all('/\b(50[1-8]|3\d{2})\b/', $text, $matches);

        foreach ($matches[1] ?? [] as $route) {
            $normalized = $this->normalizeRouteId((string) $route);
            if ($normalized !== null) {
                $matched[] = $normalized;
            }
        }

        return array_values(array_unique($matched));
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
        foreach ($keywords as $keyword) {
            if ($keyword === '') {
                continue;
            }

            $pattern = '/\b'.str_replace('\ ', '\s+', preg_quote($keyword, '/')).'\b/i';
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
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
