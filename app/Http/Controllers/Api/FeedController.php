<?php

namespace App\Http\Controllers\Api;

use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\UnifiedAlertResource;
use App\Rules\UnifiedAlertsCursorRule;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use App\Services\Alerts\UnifiedAlertsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FeedController extends Controller
{
    /**
     * Get a batch of alerts using cursor-based pagination.
     *
     * Returns a JSON response with alerts and the next cursor for infinite scroll.
     *
     * Query parameters:
     * - status: 'all' | 'active' | 'cleared'
     * - source: 'fire' | 'police' | 'transit' | 'go_transit'
     * - q: search query string
     * - since: '30m' | '1h' | '3h' | '6h' | '12h'
     * - sort: 'desc' (default) | 'asc'
     * - cursor: opaque cursor string for pagination
     *
     * @return JsonResponse{data: array<int, mixed>, next_cursor: string|null}
     */
    public function __invoke(Request $request, UnifiedAlertsQuery $alerts): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(AlertStatus::class)],
            'source' => ['nullable', Rule::enum(AlertSource::class)],
            'q' => ['nullable', 'string', 'max:200'],
            'since' => ['nullable', Rule::in(UnifiedAlertsCriteria::SINCE_OPTIONS)],
            'sort' => ['nullable', Rule::in(UnifiedAlertsCriteria::SORT_OPTIONS)],
            'cursor' => ['nullable', 'string', 'max:512', new UnifiedAlertsCursorRule],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $criteria = new UnifiedAlertsCriteria(
            status: $validated['status'] ?? 'all',
            sort: $validated['sort'] ?? null,
            source: $validated['source'] ?? null,
            query: $validated['q'] ?? null,
            since: $validated['since'] ?? null,
            cursor: $validated['cursor'] ?? null,
            perPage: $validated['per_page'] ?? UnifiedAlertsCriteria::DEFAULT_PER_PAGE,
        );

        $result = $alerts->cursorPaginate($criteria);

        return response()->json([
            'data' => UnifiedAlertResource::collection($result['items'])->resolve(),
            'next_cursor' => $result['next_cursor'],
        ]);
    }
}
