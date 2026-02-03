<?php

namespace App\Http\Controllers;

use App\Http\Resources\UnifiedAlertResource;
use App\Models\FireIncident;
use App\Models\PoliceCall;
use App\Services\Alerts\UnifiedAlertsQuery;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GtaAlertsController extends Controller
{
    public function __invoke(Request $request, UnifiedAlertsQuery $alerts): Response
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['all', 'active', 'cleared'])],
        ]);

        /** @var 'all'|'active'|'cleared' $status */
        $status = $validated['status'] ?? 'all';

        $paginator = $alerts->paginate(perPage: 50, status: $status);
        $paginator->withQueryString();

        $latestFeedUpdatedAt = $this->latestFeedUpdatedAt();

        return Inertia::render('gta-alerts', [
            'alerts' => UnifiedAlertResource::collection($paginator),
            'filters' => [
                'status' => $status,
            ],
            'latest_feed_updated_at' => $latestFeedUpdatedAt?->toIso8601String(),
        ]);
    }

    private function latestFeedUpdatedAt(): ?\Carbon\CarbonInterface
    {
        $fire = FireIncident::query()
            ->whereNotNull('feed_updated_at')
            ->orderByDesc('feed_updated_at')
            ->first(['feed_updated_at'])
            ?->feed_updated_at;

        $police = PoliceCall::query()
            ->whereNotNull('feed_updated_at')
            ->orderByDesc('feed_updated_at')
            ->first(['feed_updated_at'])
            ?->feed_updated_at;

        if ($fire === null) {
            return $police;
        }

        if ($police === null) {
            return $fire;
        }

        return $fire->greaterThan($police) ? $fire : $police;
    }
}
