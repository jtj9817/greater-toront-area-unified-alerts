<?php

namespace App\Enums;

enum IncidentUpdateType: string
{
    case MILESTONE = 'milestone';
    case RESOURCE_STATUS = 'resource_status';
    case ALARM_CHANGE = 'alarm_change';
    case PHASE_CHANGE = 'phase_change';
    case MANUAL_NOTE = 'manual_note';

    public function label(): string
    {
        return match ($this) {
            self::MILESTONE => 'Milestone',
            self::RESOURCE_STATUS => 'Resource Update',
            self::ALARM_CHANGE => 'Alarm Level Change',
            self::PHASE_CHANGE => 'Phase Change',
            self::MANUAL_NOTE => 'Manual Note',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::MILESTONE => 'flag',
            self::RESOURCE_STATUS => 'local_fire_department',
            self::ALARM_CHANGE => 'trending_up',
            self::PHASE_CHANGE => 'sync',
            self::MANUAL_NOTE => 'note',
        };
    }
}
