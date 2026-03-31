<?php

namespace App\Services\Alerts\Providers;

use App\Enums\AlertStatus;
use App\Models\MiwayAlert;
use App\Services\Alerts\Contracts\AlertSelectProvider;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class MiwayAlertSelectProvider implements AlertSelectProvider
{
    public function source(): string
    {
        return 'miway';
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
                'header_text', header_text,
                'description_text', description_text,
                'cause', cause,
                'effect', effect,
                'url', url,
                'detour_pdf_url', detour_pdf_url,
                'ends_at', ends_at,
                'feed_updated_at', feed_updated_at
            )";
        } elseif ($driver === 'pgsql') {
            $idExpression = "('{$source}:' || CAST(external_id AS text))";
            $externalIdExpression = 'CAST(external_id AS text)';
            $latExpression = 'CAST(NULL AS double precision)';
            $lngExpression = 'CAST(NULL AS double precision)';
            $metaExpression = "json_build_object(
                'header_text', header_text,
                'description_text', description_text,
                'cause', cause,
                'effect', effect,
                'url', url,
                'detour_pdf_url', detour_pdf_url,
                'ends_at', ends_at,
                'feed_updated_at', feed_updated_at
            )::jsonb";
        } else {
            $idExpression = "CONCAT('{$source}:', external_id)";
            $externalIdExpression = 'external_id';
            $latExpression = 'NULL';
            $lngExpression = 'NULL';
            $metaExpression = "JSON_OBJECT(
                'header_text', header_text,
                'description_text', description_text,
                'cause', cause,
                'effect', effect,
                'url', url,
                'detour_pdf_url', detour_pdf_url,
                'ends_at', ends_at,
                'feed_updated_at', feed_updated_at
            )";
        }

        $query = MiwayAlert::query()
            ->selectRaw(
                "{$idExpression} as id,\n                '{$source}' as source,\n                {$externalIdExpression} as external_id,\n                is_active,\n                COALESCE(starts_at, created_at) as timestamp,\n                header_text as title,\n                NULL as location_name,\n                {$latExpression} as lat,\n                {$lngExpression} as lng,\n                {$metaExpression} as meta"
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
                    $nested->whereNotNull('starts_at')
                        ->where('starts_at', '>=', $cutoff);
                })->orWhere(function ($nested) use ($cutoff) {
                    $nested->whereNull('starts_at')
                        ->where('created_at', '>=', $cutoff);
                });
            });
        }

        if ($criteria->query !== null) {
            $needle = '%'.mb_strtolower($criteria->query).'%';

            if ($isMySqlFamily) {
                $query->where(function ($where) use ($criteria, $needle) {
                    $where->whereRaw(
                        'MATCH(header_text, description_text) AGAINST (? IN NATURAL LANGUAGE MODE)',
                        [$criteria->query],
                    )->orWhereRaw('LOWER(header_text) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(description_text) LIKE ?', [$needle]);
                });
            } elseif ($driver === 'pgsql') {
                $query->where(function ($where) use ($criteria, $needle) {
                    $where->whereRaw(
                        "to_tsvector('simple', coalesce(header_text, '') || ' ' || coalesce(description_text, '')) @@ plainto_tsquery('simple', ?)",
                        [$criteria->query],
                    )->orWhereRaw("coalesce(header_text, '') ILIKE ?", [$needle])
                        ->orWhereRaw("coalesce(description_text, '') ILIKE ?", [$needle]);
                });
            }
        }

        return $query->toBase();
    }
}
