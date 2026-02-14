import { describe, expect, it } from 'vitest';

import {
    buildFireDescriptionAndMetadata,
    deriveFirePresentationType,
    deriveFireSeverity,
} from './presentation';
import type { FireAlert } from './schema';

function makeFireAlert(overrides: Partial<FireAlert> = {}): FireAlert {
    return {
        kind: 'fire',
        id: 'fire:E1',
        externalId: 'E1',
        isActive: true,
        timestamp: new Date('2026-02-03T12:00:00Z').toISOString(),
        title: 'STRUCTURE FIRE',
        location: { name: 'MAIN ST', lat: null, lng: null },
        meta: {
            alarm_level: 2,
            event_num: 'E1',
            units_dispatched: 'P1, P2',
            beat: 'B1',
            intel_summary: [],
            intel_last_updated: null,
        },
        ...overrides,
    };
}

describe('fire presentation', () => {
    it.each([
        ['STRUCTURE FIRE', 'fire'],
        ['GAS LEAK', 'hazard'],
        ['MEDICAL RESPONSE', 'medical'],
        ['UNKNOWN EVENT', 'fire'],
    ] as const)('derives type %s -> %s', (title, expected) => {
        expect(deriveFirePresentationType(title)).toBe(expected);
    });

    it.each([
        [2, 'high'],
        [1, 'medium'],
        [0, 'low'],
    ] as const)(
        'derives severity from alarm level %s -> %s',
        (alarmLevel, expected) => {
            const alert = makeFireAlert({
                meta: {
                    alarm_level: alarmLevel,
                    event_num: 'E1',
                    units_dispatched: null,
                    beat: null,
                    intel_summary: [],
                },
            });

            expect(deriveFireSeverity(alert)).toBe(expected);
        },
    );

    it('builds fire description and metadata with event_num fallback', () => {
        const alert = makeFireAlert({
            externalId: 'E999',
            meta: {
                alarm_level: 1,
                event_num: '',
                units_dispatched: null,
                beat: null,
                intel_summary: [],
            },
        });

        const result = buildFireDescriptionAndMetadata(alert);
        expect(result.description).toContain('Event #E999');
        expect(result.metadata?.source).toBe('Toronto Fire Services');
        expect(result.metadata?.alarmLevel).toBe(1);
    });
});
