<?php

namespace App\Services\Alerts\Providers;

use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\PoliceCall;
use App\Services\Alerts\Contracts\AlertSelectProvider;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PoliceAlertSelectProvider implements AlertSelectProvider
{
    public function source(): string
    {
        return AlertSource::Police->value;
    }

    public function select(UnifiedAlertsCriteria $criteria): Builder
    {
        $driver = DB::getDriverName();
        $isMySqlFamily = in_array($driver, ['mysql', 'mariadb'], true);
        $source = $this->source();

        if ($driver === 'sqlite') {
            $idExpression = "('{$source}:' || object_id)";
            $externalIdExpression = 'CAST(object_id AS TEXT)';
            $latExpression = 'latitude';
            $lngExpression = 'longitude';
            $metaExpression = "json_object('division', division, 'call_type_code', call_type_code, 'object_id', object_id)";
        } elseif ($driver === 'pgsql') {
            $idExpression = "('{$source}:' || CAST(object_id AS text))";
            $externalIdExpression = 'CAST(object_id AS text)';
            $latExpression = 'CAST(latitude AS double precision)';
            $lngExpression = 'CAST(longitude AS double precision)';
            $metaExpression = "json_build_object('division', division, 'call_type_code', call_type_code, 'object_id', object_id)::jsonb";
        } else {
            $idExpression = "CONCAT('{$source}:', object_id)";
            $externalIdExpression = 'CAST(object_id AS CHAR)';
            $latExpression = 'latitude';
            $lngExpression = 'longitude';
            $metaExpression = "JSON_OBJECT('division', division, 'call_type_code', call_type_code, 'object_id', object_id)";
        }

        $query = PoliceCall::query()
            ->selectRaw(
                "{$idExpression} as id,\n                '{$source}' as source,\n                {$externalIdExpression} as external_id,\n                is_active,\n                occurrence_time as timestamp,\n                call_type as title,\n                cross_streets as location_name,\n                {$latExpression} as lat,\n                {$lngExpression} as lng,\n                {$metaExpression} as meta"
            );

        if ($criteria->source !== null && $criteria->source !== $source) {
            $query->whereRaw('1 = 0');
        }

        if ($criteria->status === AlertStatus::Active->value) {
            $query->where('is_active', true);
        } elseif ($criteria->status === AlertStatus::Cleared->value) {
            $query->where('is_active', false);
        }

        if ($criteria->sinceCutoff !== null) {
            $query->where('occurrence_time', '>=', $criteria->sinceCutoff->toDateTimeString());
        }

        if ($criteria->query !== null && $isMySqlFamily) {
            $needle = '%'.mb_strtolower($criteria->query).'%';

            $query->where(function ($where) use ($criteria, $needle) {
                $where->whereRaw(
                    'MATCH(call_type, cross_streets) AGAINST (? IN NATURAL LANGUAGE MODE)',
                    [$criteria->query],
                )->orWhereRaw('LOWER(call_type) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(cross_streets) LIKE ?', [$needle]);
            });
        }

        return $query->toBase();
    }
}
