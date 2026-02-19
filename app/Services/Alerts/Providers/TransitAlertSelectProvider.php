<?php

namespace App\Services\Alerts\Providers;

use App\Enums\AlertSource;
use App\Models\TransitAlert;
use App\Services\Alerts\Contracts\AlertSelectProvider;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class TransitAlertSelectProvider implements AlertSelectProvider
{
    public function select(UnifiedAlertsCriteria $criteria): Builder
    {
        $driver = DB::getDriverName();
        $source = AlertSource::Transit->value;

        $idExpression = $driver === 'sqlite'
            ? "('{$source}:' || external_id)"
            : "CONCAT('{$source}:', external_id)";

        $locationExpression = $driver === 'sqlite'
            ? "NULLIF(trim(\n                COALESCE('Route ' || route, '') ||\n                CASE WHEN route IS NOT NULL AND (stop_start IS NOT NULL OR stop_end IS NOT NULL) THEN ': ' ELSE '' END ||\n                COALESCE(stop_start, '') ||\n                CASE WHEN stop_start IS NOT NULL AND stop_end IS NOT NULL THEN ' to ' ELSE '' END ||\n                COALESCE(stop_end, '')\n            ), '')"
            : "NULLIF(TRIM(CONCAT(\n                IF(route IS NOT NULL, CONCAT('Route ', route), ''),\n                IF(route IS NOT NULL AND (stop_start IS NOT NULL OR stop_end IS NOT NULL), ': ', ''),\n                IFNULL(stop_start, ''),\n                IF(stop_start IS NOT NULL AND stop_end IS NOT NULL, ' to ', ''),\n                IFNULL(stop_end, '')\n            )), '')";

        $metaExpression = $driver === 'sqlite'
            ? "json_object('route_type', route_type, 'route', route, 'severity', severity, 'effect', effect, 'source_feed', source_feed, 'alert_type', alert_type, 'description', description, 'url', url, 'direction', direction, 'cause', cause, 'stop_start', stop_start, 'stop_end', stop_end)"
            : "JSON_OBJECT('route_type', route_type, 'route', route, 'severity', severity, 'effect', effect, 'source_feed', source_feed, 'alert_type', alert_type, 'description', description, 'url', url, 'direction', direction, 'cause', cause, 'stop_start', stop_start, 'stop_end', stop_end)";

        $query = TransitAlert::query()
            ->selectRaw(
                "{$idExpression} as id,\n                '{$source}' as source,\n                external_id,\n                is_active,\n                COALESCE(active_period_start, created_at) as timestamp,\n                title,\n                {$locationExpression} as location_name,\n                NULL as lat,\n                NULL as lng,\n                {$metaExpression} as meta"
            );

        if ($criteria->query !== null && $driver === 'mysql') {
            $query->whereRaw(
                'MATCH(title, description, stop_start, stop_end, route, route_type) AGAINST (? IN NATURAL LANGUAGE MODE)',
                [$criteria->query],
            );
        }

        return $query->toBase();
    }
}
