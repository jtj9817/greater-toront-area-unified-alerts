<?php

namespace App\Http\Controllers;

use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Http\Resources\UnifiedAlertResource;
use App\Models\FireIncident;
use App\Models\GoTransitAlert;
use App\Models\PoliceCall;
use App\Models\SavedAlert;
use App\Models\TransitAlert;
use App\Rules\UnifiedAlertsCursorRule;
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
            'source' => ['nullable', Rule::enum(AlertSource::class)],
            'q' => ['nullable', 'string', 'max:200'],
            'since' => ['nullable', Rule::in(UnifiedAlertsCriteria::SINCE_OPTIONS)],
            'sort' => ['nullable', Rule::in(UnifiedAlertsCriteria::SORT_OPTIONS)],
            'cursor' => ['nullable', 'string', 'max:512', new UnifiedAlertsCursorRule],
        ]);

        $status = AlertStatus::normalize($validated['status'] ?? null);
        $page = $request->integer('page');

        $criteria = new UnifiedAlertsCriteria(
            status: $status,
            sort: $validated['sort'] ?? null,
            source: $validated['source'] ?? null,
            query: $validated['q'] ?? null,
            since: $validated['since'] ?? null,
            cursor: $validated['cursor'] ?? null,
            perPage: UnifiedAlertsCriteria::DEFAULT_PER_PAGE,
            page: $page > 0 ? $page : null,
        );

        // Use cursor pagination for infinite scroll instead of traditional pagination
        $result = $alerts->cursorPaginate($criteria);

        $latestFeedUpdatedAt = $this->latestFeedUpdatedAt();

        return Inertia::render('gta-alerts', [
            'alerts' => [
                'data' => UnifiedAlertResource::collection($result['items'])->resolve(),
                'next_cursor' => $result['next_cursor'],
            ],
            'filters' => [
                'status' => $status,
                'sort' => $criteria->sort,
                'source' => $criteria->source,
                'q' => $criteria->query,
                'since' => $criteria->since,
            ],
            'latest_feed_updated_at' => $latestFeedUpdatedAt?->toIso8601String(),
            'subscription_route_options' => $this->subscriptionRouteOptions(),
            'saved_alert_ids' => $this->savedAlertIds($request),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function savedAlertIds(Request $request): array
    {
        if (! $request->user()) {
            return [];
        }

        return SavedAlert::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->pluck('alert_id')
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function subscriptionRouteOptions(): array
    {
        $routes = config('transit_data.routes');

        if (! is_array($routes)) {
            return [];
        }

        return collect($routes)
            ->filter(static fn (mixed $route): bool => is_array($route))
            ->map(static fn (array $route): string => trim((string) ($route['id'] ?? '')))
            ->filter(static fn (string $routeId): bool => $routeId !== '')
            ->unique()
            ->sort(SORT_NATURAL)
            ->values()
            ->all();
    }

    private function latestFeedUpdatedAt(): ?\Carbon\CarbonInterface
    {
        return collect([
            $this->latestFeedTimestamp(FireIncident::class),
            $this->latestFeedTimestamp(PoliceCall::class),
            $this->latestFeedTimestamp(TransitAlert::class),
            $this->latestFeedTimestamp(GoTransitAlert::class),
        ])
            ->filter()
            ->sortDesc()
            ->first();
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
