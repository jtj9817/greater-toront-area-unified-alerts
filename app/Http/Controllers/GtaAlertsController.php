<?php

namespace App\Http\Controllers;

use App\Http\Resources\FireIncidentResource;
use App\Models\FireIncident;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GtaAlertsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $query = FireIncident::query()
            ->active()
            ->orderByDesc('dispatch_time');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('prime_street', 'like', "%{$search}%")
                    ->orWhere('cross_streets', 'like', "%{$search}%")
                    ->orWhere('event_num', 'like', "%{$search}%")
                    ->orWhere('event_type', 'like', "%{$search}%");
            });
        }

        $incidents = $query->paginate(50)->withQueryString();

        $latestFeedUpdatedAt = FireIncident::query()
            ->whereNotNull('feed_updated_at')
            ->orderByDesc('feed_updated_at')
            ->first(['feed_updated_at'])
            ?->feed_updated_at;

        return Inertia::render('gta-alerts', [
            'incidents' => FireIncidentResource::collection($incidents),
            'filters' => $request->only(['search']),
            'latest_feed_updated_at' => $latestFeedUpdatedAt?->toIso8601String(),
        ]);
    }
}
