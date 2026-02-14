<?php

namespace App\Services\Alerts\Providers;

use App\Enums\AlertSource;
use App\Models\FireIncident;
use App\Services\Alerts\Contracts\AlertSelectProvider;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class FireAlertSelectProvider implements AlertSelectProvider
{
    public function select(): Builder
    {
        $driver = DB::getDriverName();
        $source = AlertSource::Fire->value;

        $idExpression = $driver === 'sqlite'
            ? "('{$source}:' || event_num)"
            : "CONCAT('{$source}:', event_num)";

        $externalIdExpression = $driver === 'sqlite'
            ? 'CAST(event_num AS TEXT)'
            : 'CAST(event_num AS CHAR)';

        $locationExpression = $driver === 'sqlite'
            ? "CASE\n                WHEN prime_street IS NOT NULL AND cross_streets IS NOT NULL THEN prime_street || ' / ' || cross_streets\n                WHEN prime_street IS NOT NULL THEN prime_street\n                WHEN cross_streets IS NOT NULL THEN cross_streets\n                ELSE NULL\n            END"
            : "NULLIF(CONCAT_WS(' / ', prime_street, cross_streets), '')";

        $summaryExpression = $this->getSummarySubquery($driver);
        $lastUpdatedExpression = $this->getLastUpdatedSubquery($driver);

        $metaExpression = $driver === 'sqlite'
            ? "json_object(
                'alarm_level', alarm_level, 
                'units_dispatched', units_dispatched, 
                'beat', beat, 
                'event_num', event_num,
                'intel_summary', json(({$summaryExpression})),
                'intel_last_updated', ({$lastUpdatedExpression})
            )"
            : "JSON_OBJECT(
                'alarm_level', alarm_level, 
                'units_dispatched', units_dispatched, 
                'beat', beat, 
                'event_num', event_num,
                'intel_summary', ({$summaryExpression}),
                'intel_last_updated', ({$lastUpdatedExpression})
            )";

        return FireIncident::query()
            ->selectRaw(
                "{$idExpression} as id,\n                '{$source}' as source,\n                {$externalIdExpression} as external_id,\n                is_active,\n                dispatch_time as timestamp,\n                event_type as title,\n                {$locationExpression} as location_name,\n                NULL as lat,\n                NULL as lng,\n                {$metaExpression} as meta"
            )
            ->toBase();
    }

    private function getSummarySubquery(string $driver): string
    {
        if ($driver === 'sqlite') {
            return "
                SELECT json_group_array(
                    json_object(
                        'id', id,
                        'type', update_type,
                        'type_label', CASE update_type
                            WHEN 'milestone' THEN 'Milestone'
                            WHEN 'resource_status' THEN 'Resource Update'
                            WHEN 'alarm_change' THEN 'Alarm Level Change'
                            WHEN 'phase_change' THEN 'Phase Change'
                            WHEN 'manual_note' THEN 'Manual Note'
                            ELSE update_type END,
                        'icon', CASE update_type
                            WHEN 'milestone' THEN 'flag'
                            WHEN 'resource_status' THEN 'local_fire_department'
                            WHEN 'alarm_change' THEN 'trending_up'
                            WHEN 'phase_change' THEN 'sync'
                            WHEN 'manual_note' THEN 'note'
                            ELSE 'info' END,
                        'content', content,
                        'timestamp', strftime('%Y-%m-%dT%H:%M:%SZ', created_at),
                        'metadata', json(metadata)
                    )
                )
                FROM (
                    SELECT id, update_type, content, created_at, metadata
                    FROM incident_updates
                    WHERE incident_updates.event_num = fire_incidents.event_num
                    ORDER BY created_at DESC
                    LIMIT 3
                )
            ";
        }

        // MySQL
        return "
            SELECT COALESCE(JSON_ARRAYAGG(
                JSON_OBJECT(
                    'id', t.id,
                    'type', t.update_type,
                    'type_label', CASE t.update_type
                        WHEN 'milestone' THEN 'Milestone'
                        WHEN 'resource_status' THEN 'Resource Update'
                        WHEN 'alarm_change' THEN 'Alarm Level Change'
                        WHEN 'phase_change' THEN 'Phase Change'
                        WHEN 'manual_note' THEN 'Manual Note'
                        ELSE t.update_type END,
                    'icon', CASE t.update_type
                        WHEN 'milestone' THEN 'flag'
                        WHEN 'resource_status' THEN 'local_fire_department'
                        WHEN 'alarm_change' THEN 'trending_up'
                        WHEN 'phase_change' THEN 'sync'
                        WHEN 'manual_note' THEN 'note'
                        ELSE 'info' END,
                    'content', t.content,
                    'timestamp', DATE_FORMAT(t.created_at, '%Y-%m-%dT%T.000000Z'),
                    'metadata', t.metadata
                )
            ), JSON_ARRAY())
            FROM LATERAL (
                SELECT id, update_type, content, created_at, metadata
                FROM incident_updates
                WHERE incident_updates.event_num = fire_incidents.event_num
                ORDER BY created_at DESC
                LIMIT 3
            ) as t
        ";
    }

    private function getLastUpdatedSubquery(string $driver): string
    {
        return 'SELECT MAX(created_at) FROM incident_updates WHERE incident_updates.event_num = fire_incidents.event_num';
    }
}
