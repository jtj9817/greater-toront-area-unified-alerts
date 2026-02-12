<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class SubscriptionOptionsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $config = config('transit_data');

        $routes = collect($config['routes'] ?? [])
            ->filter(static fn (mixed $route): bool => is_array($route))
            ->map(static fn (array $route): array => [
                'urn' => 'route:'.trim((string) ($route['id'] ?? '')),
                'id' => trim((string) ($route['id'] ?? '')),
                'name' => trim((string) ($route['name'] ?? '')),
            ])
            ->filter(static fn (array $route): bool => $route['id'] !== '')
            ->values()
            ->all();

        $stations = collect($config['stations'] ?? [])
            ->filter(static fn (mixed $station): bool => is_array($station))
            ->map(static fn (array $station): array => [
                'urn' => 'station:'.trim((string) ($station['slug'] ?? '')),
                'slug' => trim((string) ($station['slug'] ?? '')),
                'name' => trim((string) ($station['name'] ?? '')),
            ])
            ->filter(static fn (array $station): bool => $station['slug'] !== '')
            ->values()
            ->all();

        $lines = collect($config['lines'] ?? [])
            ->filter(static fn (mixed $line): bool => is_array($line))
            ->map(static fn (array $line): array => [
                'urn' => 'line:'.trim((string) ($line['id'] ?? '')),
                'id' => trim((string) ($line['id'] ?? '')),
                'name' => trim((string) ($line['name'] ?? '')),
            ])
            ->filter(static fn (array $line): bool => $line['id'] !== '')
            ->values()
            ->all();

        return response()->json([
            'data' => [
                'agency' => [
                    'urn' => 'agency:'.trim((string) ($config['agency']['slug'] ?? 'ttc')),
                    'name' => trim((string) ($config['agency']['name'] ?? 'TTC')),
                ],
                'routes' => $routes,
                'stations' => $stations,
                'lines' => $lines,
            ],
        ]);
    }
}
