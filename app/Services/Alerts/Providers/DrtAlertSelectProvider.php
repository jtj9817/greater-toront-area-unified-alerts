<?php

namespace App\Services\Alerts\Providers;

use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\DrtAlert;
use App\Services\Alerts\Contracts\AlertSelectProvider;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class DrtAlertSelectProvider implements AlertSelectProvider
{
    public function source(): string
    {
        return AlertSource::Drt->value;
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
            $metaExpression = "json_object(
                'details_url', details_url,
                'when_text', when_text,
                'route_text', route_text,
                'body_text', body_text,
                'feed_updated_at', feed_updated_at,
                'posted_at', posted_at
            )";
        } elseif ($driver === 'pgsql') {
            $idExpression = "('{$source}:' || CAST(external_id AS text))";
            $externalIdExpression = 'CAST(external_id AS text)';
            $latExpression = 'CAST(NULL AS double precision)';
            $lngExpression = 'CAST(NULL AS double precision)';
            $metaExpression = "json_build_object(
                'details_url', details_url,
                'when_text', when_text,
                'route_text', route_text,
                'body_text', body_text,
                'feed_updated_at', feed_updated_at,
                'posted_at', posted_at
            )::jsonb";
        } else {
            $idExpression = "CONCAT('{$source}:', external_id)";
            $externalIdExpression = 'external_id';
            $latExpression = 'NULL';
            $lngExpression = 'NULL';
            $metaExpression = "JSON_OBJECT(
                'details_url', details_url,
                'when_text', when_text,
                'route_text', route_text,
                'body_text', body_text,
                'feed_updated_at', feed_updated_at,
                'posted_at', posted_at
            )";
        }

        $query = DrtAlert::query()
            ->selectRaw(
                "{$idExpression} as id,
                '{$source}' as source,
                {$externalIdExpression} as external_id,
                is_active,
                posted_at as timestamp,
                title,
                route_text as location_name,
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
                        'MATCH(title, route_text, body_text, when_text) AGAINST (? IN NATURAL LANGUAGE MODE)',
                        [$criteria->query],
                    )->orWhereRaw('LOWER(title) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(route_text) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(body_text) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(when_text) LIKE ?', [$needle]);
                });
            } elseif ($driver === 'pgsql') {
                $query->where(function ($where) use ($criteria, $needle) {
                    $where->whereRaw(
                        "to_tsvector('simple', coalesce(title, '') || ' ' || coalesce(route_text, '') || ' ' || coalesce(body_text, '') || ' ' || coalesce(when_text, '')) @@ plainto_tsquery('simple', ?)",
                        [$criteria->query],
                    )->orWhereRaw("coalesce(title, '') ILIKE ?", [$needle])
                        ->orWhereRaw("coalesce(route_text, '') ILIKE ?", [$needle])
                        ->orWhereRaw("coalesce(body_text, '') ILIKE ?", [$needle])
                        ->orWhereRaw("coalesce(when_text, '') ILIKE ?", [$needle]);
                });
            }
        }

        return $query->toBase();
    }
}
