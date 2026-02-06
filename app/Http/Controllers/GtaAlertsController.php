<?php

namespace App\Http\Controllers;

use App\Enums\AlertStatus;
use App\Http\Resources\UnifiedAlertResource;
use App\Models\FireIncident;
use App\Models\PoliceCall;
use App\Models\TransitAlert;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
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
            'status' => ['nullable', Rule::enum(AlertStatus::class)],
        ]);

        $status = AlertStatus::normalize($validated['status'] ?? null);
        $page = $request->integer('page');

        $criteria = new UnifiedAlertsCriteria(
            status: $status,
            perPage: UnifiedAlertsCriteria::DEFAULT_PER_PAGE,
            page: $page > 0 ? $page : null,
        );

        $paginator = $alerts->paginate($criteria);
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
        $latest = null;

        foreach ([
            $this->latestFeedTimestamp(FireIncident::class),
            $this->latestFeedTimestamp(PoliceCall::class),
            $this->latestFeedTimestamp(TransitAlert::class),
        ] as $timestamp) {
            if ($timestamp !== null && ($latest === null || $timestamp->greaterThan($latest))) {
                $latest = $timestamp;
            }
        }

        return $latest;
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     */
    private function latestFeedTimestamp(string $modelClass): ?\Carbon\CarbonInterface
    {
        return $modelClass::query()
            ->whereNotNull('feed_updated_at')
            ->orderByDesc('feed_updated_at')
            ->first(['feed_updated_at'])
            ?->feed_updated_at;
    }
}
