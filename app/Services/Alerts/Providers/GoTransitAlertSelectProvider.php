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
        $isMySqlFamily = in_array($driver, ['mysql', 'mariadb'], true);
        $source = $this->source();

        if ($driver === 'sqlite') {
            $idExpression = "('{$source}:' || external_id)";
            $externalIdExpression = 'external_id';
            $latExpression = 'NULL';
            $lngExpression = 'NULL';
            $metaExpression = "json_object('alert_type', alert_type, 'service_mode', service_mode, 'sub_category', sub_category, 'corridor_code', corridor_code, 'direction', direction, 'trip_number', trip_number, 'delay_duration', delay_duration, 'line_colour', line_colour, 'message_body', message_body)";
        } elseif ($driver === 'pgsql') {
            $idExpression = "('{$source}:' || CAST(external_id AS text))";
            $externalIdExpression = 'CAST(external_id AS text)';
            $latExpression = 'CAST(NULL AS double precision)';
            $lngExpression = 'CAST(NULL AS double precision)';
            $metaExpression = "json_build_object('alert_type', alert_type, 'service_mode', service_mode, 'sub_category', sub_category, 'corridor_code', corridor_code, 'direction', direction, 'trip_number', trip_number, 'delay_duration', delay_duration, 'line_colour', line_colour, 'message_body', message_body)::jsonb";
        } else {
            $idExpression = "CONCAT('{$source}:', external_id)";
            $externalIdExpression = 'external_id';
            $latExpression = 'NULL';
            $lngExpression = 'NULL';
            $metaExpression = "JSON_OBJECT('alert_type', alert_type, 'service_mode', service_mode, 'sub_category', sub_category, 'corridor_code', corridor_code, 'direction', direction, 'trip_number', trip_number, 'delay_duration', delay_duration, 'line_colour', line_colour, 'message_body', message_body)";
        }

        $query = GoTransitAlert::query()
            ->selectRaw(
                "{$idExpression} as id,
                '{$source}' as source,
                {$externalIdExpression} as external_id,
                is_active,
                posted_at as timestamp,
                message_subject as title,
                corridor_or_route as location_name,
                {$latExpression} as lat,
                {$lngExpression} as lng,
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

        if ($criteria->query !== null) {
            $needle = '%'.mb_strtolower($criteria->query).'%';

            if ($isMySqlFamily) {
                $query->where(function ($where) use ($criteria, $needle) {
                    $where->whereRaw(
                        'MATCH(message_subject, message_body, corridor_or_route, corridor_code, service_mode) AGAINST (? IN NATURAL LANGUAGE MODE)',
                        [$criteria->query],
                    )->orWhereRaw('LOWER(message_subject) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(message_body) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(corridor_or_route) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(corridor_code) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(service_mode) LIKE ?', [$needle]);
                });
            } elseif ($driver === 'pgsql') {
                $query->where(function ($where) use ($criteria, $needle) {
                    $where->whereRaw(
                        "to_tsvector('simple', coalesce(message_subject, '') || ' ' || coalesce(message_body, '') || ' ' || coalesce(corridor_or_route, '') || ' ' || coalesce(corridor_code, '') || ' ' || coalesce(service_mode, '')) @@ plainto_tsquery('simple', ?)",
                        [$criteria->query],
                    )->orWhereRaw("coalesce(message_subject, '') ILIKE ?", [$needle])
                        ->orWhereRaw("coalesce(message_body, '') ILIKE ?", [$needle])
                        ->orWhereRaw("coalesce(corridor_or_route, '') ILIKE ?", [$needle])
                        ->orWhereRaw("coalesce(corridor_code, '') ILIKE ?", [$needle])
                        ->orWhereRaw("coalesce(service_mode, '') ILIKE ?", [$needle]);
                });
            }
        }

        return $query->toBase();
    }
}
