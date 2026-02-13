<?php

namespace App\Services\SceneIntel;

use App\Enums\IncidentUpdateType;
use App\Models\FireIncident;
use App\Models\IncidentUpdate;

class SceneIntelProcessor
{
    /**
     * @param  array{
     *     alarm_level?: int|string|null,
     *     units_dispatched?: string|null,
     *     is_active?: bool|int|string|null
     * }|null  $previousData
     */
    public function processIncidentUpdate(FireIncident $incident, ?array $previousData): void
    {
        if ($previousData === null) {
            return;
        }

        $this->processAlarmLevelChange($incident, $previousData);
        $this->processUnitChanges($incident, $previousData);
        $this->processClosureChange($incident, $previousData);
    }

    /**
     * @param  array{
     *     alarm_level?: int|string|null
     * }  $previousData
     */
    private function processAlarmLevelChange(FireIncident $incident, array $previousData): void
    {
        if (! array_key_exists('alarm_level', $previousData) || ! is_numeric($previousData['alarm_level'])) {
            return;
        }

        $previousLevel = (int) $previousData['alarm_level'];
        $currentLevel = (int) $incident->alarm_level;

        if ($previousLevel === $currentLevel) {
            return;
        }

        $direction = $currentLevel > $previousLevel ? 'up' : 'down';
        $verb = $direction === 'up' ? 'increased' : 'decreased';

        $this->createSyntheticUpdate(
            eventNum: $incident->event_num,
            updateType: IncidentUpdateType::ALARM_CHANGE,
            content: "Alarm level {$verb} from {$previousLevel} to {$currentLevel}",
            metadata: [
                'previous_level' => $previousLevel,
                'new_level' => $currentLevel,
                'direction' => $direction,
            ],
        );
    }

    /**
     * @param  array{
     *     units_dispatched?: string|null
     * }  $previousData
     */
    private function processUnitChanges(FireIncident $incident, array $previousData): void
    {
        $previousUnits = $this->parseUnits($previousData['units_dispatched'] ?? null);
        $currentUnits = $this->parseUnits($incident->units_dispatched);

        $newUnits = array_values(array_diff($currentUnits, $previousUnits));
        $clearedUnits = array_values(array_diff($previousUnits, $currentUnits));

        sort($newUnits, SORT_NATURAL);
        sort($clearedUnits, SORT_NATURAL);

        foreach ($newUnits as $unitCode) {
            $this->createSyntheticUpdate(
                eventNum: $incident->event_num,
                updateType: IncidentUpdateType::RESOURCE_STATUS,
                content: "Unit {$unitCode} dispatched",
                metadata: [
                    'unit_code' => $unitCode,
                    'status' => 'dispatched',
                ],
            );
        }

        foreach ($clearedUnits as $unitCode) {
            $this->createSyntheticUpdate(
                eventNum: $incident->event_num,
                updateType: IncidentUpdateType::RESOURCE_STATUS,
                content: "Unit {$unitCode} cleared",
                metadata: [
                    'unit_code' => $unitCode,
                    'status' => 'cleared',
                ],
            );
        }
    }

    /**
     * @param  array{
     *     is_active?: bool|int|string|null
     * }  $previousData
     */
    private function processClosureChange(FireIncident $incident, array $previousData): void
    {
        $wasActive = (bool) ($previousData['is_active'] ?? false);

        if (! $wasActive || $incident->is_active) {
            return;
        }

        if ($this->hasSyntheticClosureUpdate($incident->event_num)) {
            return;
        }

        $this->createSyntheticUpdate(
            eventNum: $incident->event_num,
            updateType: IncidentUpdateType::PHASE_CHANGE,
            content: 'Incident marked as resolved',
            metadata: [
                'previous_phase' => 'active',
                'new_phase' => 'resolved',
            ],
        );
    }

    private function hasSyntheticClosureUpdate(string $eventNum): bool
    {
        return IncidentUpdate::query()
            ->where('event_num', $eventNum)
            ->where('update_type', IncidentUpdateType::PHASE_CHANGE)
            ->where('source', 'synthetic')
            ->where('content', 'Incident marked as resolved')
            ->exists();
    }

    /**
     * @return array<int, string>
     */
    private function parseUnits(?string $unitsDispatched): array
    {
        if ($unitsDispatched === null) {
            return [];
        }

        $parts = preg_split('/\s*,\s*/', trim($unitsDispatched)) ?: [];
        $normalized = [];

        foreach ($parts as $part) {
            $unitCode = strtoupper(trim($part));

            if ($unitCode === '') {
                continue;
            }

            $normalized[$unitCode] = $unitCode;
        }

        return array_values($normalized);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function createSyntheticUpdate(
        string $eventNum,
        IncidentUpdateType $updateType,
        string $content,
        ?array $metadata = null,
    ): IncidentUpdate {
        return IncidentUpdate::query()->create([
            'event_num' => $eventNum,
            'update_type' => $updateType,
            'content' => $content,
            'metadata' => $metadata,
            'source' => 'synthetic',
            'created_by' => null,
        ]);
    }
}
