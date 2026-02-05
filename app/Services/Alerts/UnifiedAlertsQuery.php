<?php

namespace App\Services\Alerts;

use App\Enums\AlertStatus;
use App\Services\Alerts\Contracts\AlertSelectProvider;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
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
        $union = $this->unionSelect();

        if ($union === null) {
            return new LengthAwarePaginator(
                items: [],
                total: 0,
                perPage: $criteria->perPage,
                currentPage: $criteria->page ?? Paginator::resolveCurrentPage(),
                options: ['path' => Paginator::resolveCurrentPath()],
            );
        }

        $query = DB::query()->fromSub($union, 'unified_alerts');

        if ($criteria->status === AlertStatus::Active->value) {
            $query->where('is_active', true);
        } elseif ($criteria->status === AlertStatus::Cleared->value) {
            $query->where('is_active', false);
        }

        return $query
            ->orderByDesc('timestamp')
            ->orderBy('source')
            ->orderByDesc('external_id')
            ->paginate(
                $criteria->perPage,
                ['*'],
                'page',
                $criteria->page ?? Paginator::resolveCurrentPage(),
            )
            ->through(fn (object $row) => $this->mapper->fromRow($row));
    }

    private function unionSelect(): ?Builder
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
        $union = $first->select();

        foreach ($providers as $provider) {
            $union->unionAll($provider->select());
        }

        return $union;
    }
}
