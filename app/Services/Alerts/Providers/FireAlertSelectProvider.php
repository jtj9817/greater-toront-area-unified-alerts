<?php

namespace App\Services\Alerts\Providers;

use App\Models\FireIncident;
use App\Services\Alerts\Contracts\AlertSelectProvider;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class FireAlertSelectProvider implements AlertSelectProvider
{
    public function select(): Builder
    {
        $driver = DB::getDriverName();

        $idExpression = $driver === 'sqlite'
            ? "('fire:' || event_num)"
            : "CONCAT('fire:', event_num)";

        $locationExpression = $driver === 'sqlite'
            ? "CASE\n                WHEN prime_street IS NOT NULL AND cross_streets IS NOT NULL THEN prime_street || ' / ' || cross_streets\n                WHEN prime_street IS NOT NULL THEN prime_street\n                WHEN cross_streets IS NOT NULL THEN cross_streets\n                ELSE NULL\n            END"
            : "NULLIF(CONCAT_WS(' / ', prime_street, cross_streets), '')";

        $metaExpression = $driver === 'sqlite'
            ? "json_object('alarm_level', alarm_level, 'units_dispatched', units_dispatched, 'beat', beat, 'event_num', event_num)"
            : "JSON_OBJECT('alarm_level', alarm_level, 'units_dispatched', units_dispatched, 'beat', beat, 'event_num', event_num)";

        return FireIncident::query()
            ->selectRaw(
                "{$idExpression} as id,\n                'fire' as source,\n                event_num as external_id,\n                is_active,\n                dispatch_time as timestamp,\n                event_type as title,\n                {$locationExpression} as location_name,\n                NULL as lat,\n                NULL as lng,\n                {$metaExpression} as meta"
            )
            ->toBase();
    }
}
