<?php

namespace App\Services\Alerts\Providers;

use App\Models\PoliceCall;
use App\Services\Alerts\Contracts\AlertSelectProvider;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PoliceAlertSelectProvider implements AlertSelectProvider
{
    public function select(): Builder
    {
        $driver = DB::getDriverName();

        $idExpression = $driver === 'sqlite'
            ? "('police:' || object_id)"
            : "CONCAT('police:', object_id)";

        $externalIdExpression = $driver === 'sqlite'
            ? 'CAST(object_id AS TEXT)'
            : 'CAST(object_id AS CHAR)';

        $metaExpression = $driver === 'sqlite'
            ? "json_object('division', division, 'call_type_code', call_type_code, 'object_id', object_id)"
            : "JSON_OBJECT('division', division, 'call_type_code', call_type_code, 'object_id', object_id)";

        return PoliceCall::query()
            ->selectRaw(
                "{$idExpression} as id,\n                'police' as source,\n                {$externalIdExpression} as external_id,\n                is_active,\n                occurrence_time as timestamp,\n                call_type as title,\n                cross_streets as location_name,\n                latitude as lat,\n                longitude as lng,\n                {$metaExpression} as meta"
            )
            ->toBase();
    }
}
