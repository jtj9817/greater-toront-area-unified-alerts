<?php

namespace App\Services\Alerts\Providers;

use App\Services\Alerts\Contracts\AlertSelectProvider;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class TransitAlertSelectProvider implements AlertSelectProvider
{
    public function select(): Builder
    {
        return DB::query()
            ->selectRaw(
                "NULL as id,\n                'transit' as source,\n                NULL as external_id,\n                0 as is_active,\n                NULL as timestamp,\n                NULL as title,\n                NULL as location_name,\n                NULL as lat,\n                NULL as lng,\n                NULL as meta"
            )
            ->whereRaw('1 = 0');
    }
}
