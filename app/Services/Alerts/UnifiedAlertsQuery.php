<?php

namespace App\Services\Alerts;

use App\Enums\AlertStatus;
use App\Services\Alerts\Contracts\AlertSelectProvider;
use App\Services\Alerts\DTOs\UnifiedAlert;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use App\Services\Alerts\DTOs\UnifiedAlertsCursor;
use App\Services\Alerts\Mappers\UnifiedAlertMapper;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;

class UnifiedAlertsQuery
{
    public function __construct(
        #[Tag('alerts.select-providers')]
        private readonly iterable $providers,
        private readonly UnifiedAlertMapper $mapper,
    ) {}

    public function paginate(UnifiedAlertsCriteria $criteria): LengthAwarePaginator
    {
        // Sentinel: Prevent expensive queries by requiring at least 2 characters
        if ($criteria->query !== null && mb_strlen($criteria->query) < 2) {
            return new LengthAwarePaginator(
                items: [],
                total: 0,
                perPage: $criteria->perPage,
                currentPage: $criteria->page ?? Paginator::resolveCurrentPage(),
                options: ['path' => Paginator::resolveCurrentPath()],
            );
        }

        $union = $this->unionSelect($criteria);

        if ($union === null) {
            return new LengthAwarePaginator(
                items: [],
                total: 0,
                perPage: $criteria->perPage,
                currentPage: $criteria->page ?? Paginator::resolveCurrentPage(),
                options: ['path' => Paginator::resolveCurrentPath()],
            );
        }

        $query = $this->baseQuery($union, $criteria);

        return $query
            ->paginate(
                $criteria->perPage,
                ['*'],
                'page',
                $criteria->page ?? Paginator::resolveCurrentPage(),
            )
            ->through(fn (object $row) => $this->mapper->fromRow($row));
    }

    /**
     * Returns the next batch for infinite-scroll driven UX.
     *
     * @return array{items: array<int, mixed>, next_cursor: ?string}
     */
    public function cursorPaginate(UnifiedAlertsCriteria $criteria): array
    {
        // Sentinel: Prevent expensive queries by requiring at least 2 characters
        if ($criteria->query !== null && mb_strlen($criteria->query) < 2) {
            return ['items' => [], 'next_cursor' => null];
        }

        $union = $this->unionSelect($criteria);

        if ($union === null) {
            return ['items' => [], 'next_cursor' => null];
        }

        $query = $this->baseQuery($union, $criteria);

        if ($criteria->cursor !== null) {
            $cursorTs = $criteria->cursor->timestamp->toDateTimeString();
            $cursorId = $criteria->cursor->id;
            $cursorComparisonOperator = $criteria->sort === 'asc' ? '>' : '<';

            $query->where(function ($where) use ($cursorTs, $cursorId, $cursorComparisonOperator) {
                $where->where('timestamp', $cursorComparisonOperator, $cursorTs)
                    ->orWhere(function ($nested) use ($cursorTs, $cursorId, $cursorComparisonOperator) {
                        $nested->where('timestamp', '=', $cursorTs)
                            ->where('id', $cursorComparisonOperator, $cursorId);
                    });
            });
        }

        $rows = $query
            ->limit($criteria->perPage + 1)
            ->get();

        $mapped = $rows
            ->map(fn (object $row) => $this->mapper->fromRow($row))
            ->values()
            ->all();

        $items = array_slice($mapped, 0, $criteria->perPage);

        $nextCursor = null;
        if (count($mapped) > $criteria->perPage && $items !== []) {
            $last = $items[array_key_last($items)];

            if ($last instanceof UnifiedAlert) {
                $nextCursor = UnifiedAlertsCursor::fromTuple($last->timestamp, $last->id)->encode();
            }
        }

        return ['items' => $items, 'next_cursor' => $nextCursor];
    }

    /**
     * Fetch specific alerts by their canonical IDs, preserving the caller's ordering.
     *
     * @param  array<int, string>  $alertIds  Alert IDs in `{source}:{externalId}` format, ordered as desired.
     * @return array{items: array<int, UnifiedAlert>, missing_ids: array<int, string>}
     */
    public function fetchByIds(array $alertIds): array
    {
        if ($alertIds === []) {
            return ['items' => [], 'missing_ids' => []];
        }

        $criteria = new UnifiedAlertsCriteria;
        $union = $this->unionSelect($criteria);

        if ($union === null) {
            return ['items' => [], 'missing_ids' => $alertIds];
        }

        $rows = DB::query()
            ->fromSub($union, 'unified_alerts')
            ->whereIn('id', $alertIds)
            ->get();

        $mappedById = [];
        foreach ($rows as $row) {
            $alert = $this->mapper->fromRow($row);
            $mappedById[$alert->id] = $alert;
        }

        $items = [];
        foreach ($alertIds as $id) {
            if (isset($mappedById[$id])) {
                $items[] = $mappedById[$id];
            }
        }

        $missingIds = array_values(
            array_filter($alertIds, fn (string $id): bool => ! isset($mappedById[$id]))
        );

        return ['items' => $items, 'missing_ids' => $missingIds];
    }

    private function baseQuery(Builder $union, UnifiedAlertsCriteria $criteria): \Illuminate\Database\Query\Builder
    {
        $query = DB::query()->fromSub($union, 'unified_alerts');

        if ($criteria->status === AlertStatus::Active->value) {
            $query->where('is_active', true);
        } elseif ($criteria->status === AlertStatus::Cleared->value) {
            $query->where('is_active', false);
        }

        if ($criteria->source !== null) {
            $query->where('source', $criteria->source);
        }

        if ($criteria->sinceCutoff !== null) {
            $query->where('timestamp', '>=', $criteria->sinceCutoff->toDateTimeString());
        }

        if ($criteria->query !== null && DB::getDriverName() === 'sqlite') {
            $needle = '%'.mb_strtolower($criteria->query).'%';

            $query->where(function ($where) use ($needle) {
                $where->whereRaw('LOWER(title) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(location_name) LIKE ?', [$needle]);
            });
        }

        if ($criteria->sort === 'asc') {
            return $query
                ->orderBy('timestamp')
                ->orderBy('id');
        }

        return $query
            ->orderByDesc('timestamp')
            ->orderByDesc('id');
    }

    private function unionSelect(UnifiedAlertsCriteria $criteria): ?Builder
    {
        $providers = [];

        foreach ($this->providers as $provider) {
            if (! $provider instanceof AlertSelectProvider) {
                $type = is_object($provider) ? get_class($provider) : gettype($provider);
                throw new \InvalidArgumentException("Invalid provider type '{$type}'. Expected AlertSelectProvider.");
            }

            if ($criteria->source !== null && $provider->source() !== $criteria->source) {
                continue;
            }

            $providers[] = $provider;
        }

        if ($providers === []) {
            return null;
        }

        /** @var AlertSelectProvider $first */
        $first = array_shift($providers);
        $union = $first->select($criteria);

        foreach ($providers as $provider) {
            $union->unionAll($provider->select($criteria));
        }

        return $union;
    }
}
