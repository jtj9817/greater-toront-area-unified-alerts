<?php

namespace App\Http\Controllers;

use App\Http\Resources\FireIncidentResource;
use App\Models\FireIncident;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $activeIncidents = FireIncident::query()
            ->active()
            ->orderByDesc('dispatch_time')
            ->limit(100)
            ->get();

        $activeIncidentCount = FireIncident::query()
            ->active()
            ->count();

        $activeCountsByType = FireIncident::query()
            ->active()
            ->selectRaw('event_type, COUNT(*) as count')
            ->groupBy('event_type')
            ->orderByDesc('count')
            ->get()
            ->map(fn (FireIncident $row) => [
                'event_type' => (string) $row->event_type,
                'count' => (int) $row->count,
            ])
            ->all();

        $latestFeedUpdatedAt = FireIncident::query()
            ->whereNotNull('feed_updated_at')
            ->orderByDesc('feed_updated_at')
            ->first(['feed_updated_at'])
            ?->feed_updated_at;

        return Inertia::render('dashboard', [
            'active_incidents' => FireIncidentResource::collection($activeIncidents)->resolve(),
            'active_incidents_count' => $activeIncidentCount,
            'active_counts_by_type' => $activeCountsByType,
            'latest_feed_updated_at' => $latestFeedUpdatedAt?->toIso8601String(),
        ]);
    }
}
