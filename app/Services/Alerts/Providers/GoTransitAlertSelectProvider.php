<?php

namespace App\Services\Alerts\Providers;

use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\GoTransitAlert;
use App\Services\Alerts\Contracts\AlertSelectProvider;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class GoTransitAlertSelectProvider implements AlertSelectProvider
{
    public function source(): string
    {
        return AlertSource::GoTransit->value;
    }

    public function select(UnifiedAlertsCriteria $criteria): Builder
    {
        $driver = DB::getDriverName();
        $source = $this->source();

        $idExpression = $driver === 'sqlite'
            ? "('{$source}:' || external_id)"
            : "CONCAT('{$source}:', external_id)";

        $metaExpression = $driver === 'sqlite'
            ? "json_object('alert_type', alert_type, 'service_mode', service_mode, 'sub_category', sub_category, 'corridor_code', corridor_code, 'direction', direction, 'trip_number', trip_number, 'delay_duration', delay_duration, 'line_colour', line_colour, 'message_body', message_body)"
            : "JSON_OBJECT('alert_type', alert_type, 'service_mode', service_mode, 'sub_category', sub_category, 'corridor_code', corridor_code, 'direction', direction, 'trip_number', trip_number, 'delay_duration', delay_duration, 'line_colour', line_colour, 'message_body', message_body)";

        $query = GoTransitAlert::query()
            ->selectRaw(
                "{$idExpression} as id,
                '{$source}' as source,
                external_id,
                is_active,
                posted_at as timestamp,
                message_subject as title,
                corridor_or_route as location_name,
                NULL as lat,
                NULL as lng,
                {$metaExpression} as meta"
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
            $query->where('posted_at', '>=', $criteria->sinceCutoff->toDateTimeString());
        }

        if ($criteria->query !== null && $driver === 'mysql') {
            $query->whereRaw(
                'MATCH(message_subject, message_body, corridor_or_route, corridor_code, service_mode) AGAINST (? IN NATURAL LANGUAGE MODE)',
                [$criteria->query],
            );
        }

        return $query->toBase();
    }
}
