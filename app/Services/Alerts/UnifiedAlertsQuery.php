<?php

namespace App\Services\Alerts;

use App\Enums\AlertStatus;
use App\Services\Alerts\Contracts\AlertSelectProvider;
use App\Services\Alerts\DTOs\UnifiedAlertsCursor;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use App\Services\Alerts\DTOs\UnifiedAlert;
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
        $union = $this->unionSelect($criteria);

        if ($union === null) {
            return ['items' => [], 'next_cursor' => null];
        }

        $query = $this->baseQuery($union, $criteria);

        if ($criteria->cursor !== null) {
            $cursorTs = $criteria->cursor->timestamp->toDateTimeString();
            $cursorId = $criteria->cursor->id;

            $query->where(function ($where) use ($cursorTs, $cursorId) {
                $where->where('timestamp', '<', $cursorTs)
                    ->orWhere(function ($nested) use ($cursorTs, $cursorId) {
                        $nested->where('timestamp', '=', $cursorTs)
                            ->where('id', '<', $cursorId);
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
