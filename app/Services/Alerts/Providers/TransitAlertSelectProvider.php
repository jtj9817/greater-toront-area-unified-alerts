<?php

namespace App\Services\Alerts\Providers;

use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\TransitAlert;
use App\Services\Alerts\Contracts\AlertSelectProvider;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class TransitAlertSelectProvider implements AlertSelectProvider
{
    public function source(): string
    {
        return AlertSource::Transit->value;
    }

    public function select(UnifiedAlertsCriteria $criteria): Builder
    {
        $driver = DB::getDriverName();
        $isMySqlFamily = in_array($driver, ['mysql', 'mariadb'], true);
        $source = $this->source();

        if ($driver === 'sqlite') {
            $idExpression = "('{$source}:' || external_id)";
            $externalIdExpression = 'external_id';
            $locationExpression = "NULLIF(trim(\n                COALESCE('Route ' || route, '') ||\n                CASE WHEN route IS NOT NULL AND (stop_start IS NOT NULL OR stop_end IS NOT NULL) THEN ': ' ELSE '' END ||\n                COALESCE(stop_start, '') ||\n                CASE WHEN stop_start IS NOT NULL AND stop_end IS NOT NULL THEN ' to ' ELSE '' END ||\n                COALESCE(stop_end, '')\n            ), '')";
            $latExpression = 'NULL';
            $lngExpression = 'NULL';
            $metaExpression = "json_object('route_type', route_type, 'route', route, 'severity', severity, 'effect', effect, 'source_feed', source_feed, 'alert_type', alert_type, 'description', description, 'url', url, 'direction', direction, 'cause', cause, 'stop_start', stop_start, 'stop_end', stop_end)";
        } elseif ($driver === 'pgsql') {
            $idExpression = "('{$source}:' || CAST(external_id AS text))";
            $externalIdExpression = 'CAST(external_id AS text)';
            $locationExpression = "NULLIF(trim(\n                coalesce(CASE WHEN route IS NOT NULL THEN 'Route ' || route END, '') ||\n                CASE WHEN route IS NOT NULL AND (stop_start IS NOT NULL OR stop_end IS NOT NULL) THEN ': ' ELSE '' END ||\n                coalesce(stop_start, '') ||\n                CASE WHEN stop_start IS NOT NULL AND stop_end IS NOT NULL THEN ' to ' ELSE '' END ||\n                coalesce(stop_end, '')\n            ), '')";
            $latExpression = 'CAST(NULL AS double precision)';
            $lngExpression = 'CAST(NULL AS double precision)';
            $metaExpression = "json_build_object('route_type', route_type, 'route', route, 'severity', severity, 'effect', effect, 'source_feed', source_feed, 'alert_type', alert_type, 'description', description, 'url', url, 'direction', direction, 'cause', cause, 'stop_start', stop_start, 'stop_end', stop_end)::jsonb";
        } else {
            $idExpression = "CONCAT('{$source}:', external_id)";
            $externalIdExpression = 'external_id';
            $locationExpression = "NULLIF(TRIM(CONCAT(\n                IF(route IS NOT NULL, CONCAT('Route ', route), ''),\n                IF(route IS NOT NULL AND (stop_start IS NOT NULL OR stop_end IS NOT NULL), ': ', ''),\n                IFNULL(stop_start, ''),\n                IF(stop_start IS NOT NULL AND stop_end IS NOT NULL, ' to ', ''),\n                IFNULL(stop_end, '')\n            )), '')";
            $latExpression = 'NULL';
            $lngExpression = 'NULL';
            $metaExpression = "JSON_OBJECT('route_type', route_type, 'route', route, 'severity', severity, 'effect', effect, 'source_feed', source_feed, 'alert_type', alert_type, 'description', description, 'url', url, 'direction', direction, 'cause', cause, 'stop_start', stop_start, 'stop_end', stop_end)";
        }

        $query = TransitAlert::query()
            ->selectRaw(
                "{$idExpression} as id,\n                '{$source}' as source,\n                {$externalIdExpression} as external_id,\n                is_active,\n                COALESCE(active_period_start, created_at) as timestamp,\n                title,\n                {$locationExpression} as location_name,\n                {$latExpression} as lat,\n                {$lngExpression} as lng,\n                {$metaExpression} as meta"
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
            $cutoff = $criteria->sinceCutoff->toDateTimeString();

            $query->where(function ($where) use ($cutoff) {
                $where->where(function ($nested) use ($cutoff) {
                    $nested->whereNotNull('active_period_start')
                        ->where('active_period_start', '>=', $cutoff);
                })->orWhere(function ($nested) use ($cutoff) {
                    $nested->whereNull('active_period_start')
                        ->where('created_at', '>=', $cutoff);
                });
            });
        }

        if ($criteria->query !== null && $isMySqlFamily) {
            $needle = '%'.mb_strtolower($criteria->query).'%';

            $query->where(function ($where) use ($criteria, $needle) {
                $where->whereRaw(
                    'MATCH(title, description, stop_start, stop_end, route, route_type) AGAINST (? IN NATURAL LANGUAGE MODE)',
                    [$criteria->query],
                )->orWhereRaw('LOWER(title) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(route) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(route_type) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(stop_start) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(stop_end) LIKE ?', [$needle]);
            });
        }

        return $query->toBase();
    }
}
