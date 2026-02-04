<?php

use App\Services\Alerts\Contracts\AlertSelectProvider;
use App\Services\Alerts\UnifiedAlertsQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('unified alerts query resolves tagged providers and includes custom tagged providers', function () {
    $this->app->bind('alerts.providers.fake', function () {
        return new class implements AlertSelectProvider
        {
            public function select(): Builder
            {
                return DB::query()->selectRaw(
                    "? as id,\n                    ? as source,\n                    ? as external_id,\n                    ? as is_active,\n                    ? as timestamp,\n                    ? as title,\n                    ? as location_name,\n                    ? as lat,\n                    ? as lng,\n                    ? as meta",
                    [
                        'fake:1',
                        'fake',
                        '1',
                        1,
                        '2026-02-02 12:00:00',
                        'FAKE ALERT',
                        null,
                        null,
                        null,
                        null,
                    ],
                );
            }
        };
    });

    $this->app->tag(['alerts.providers.fake'], 'alerts.select-providers');
    $this->app->forgetInstance(UnifiedAlertsQuery::class);

    $results = $this->app->make(UnifiedAlertsQuery::class)->paginate(perPage: 50, status: 'all');

    expect($results->total())->toBe(1);
    expect($results->items()[0]->id)->toBe('fake:1');
});
