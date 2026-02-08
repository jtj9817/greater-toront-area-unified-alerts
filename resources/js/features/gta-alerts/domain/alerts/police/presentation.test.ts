import { describe, expect, it } from 'vitest';

import {
    buildPoliceDescriptionAndMetadata,
    derivePoliceSeverity,
} from './presentation';
import type { PoliceAlert } from './schema';

function makePoliceAlert(overrides: Partial<PoliceAlert> = {}): PoliceAlert {
    return {
        kind: 'police',
        id: 'police:123',
        externalId: '123',
        isActive: true,
        timestamp: new Date('2026-02-03T12:00:00Z').toISOString(),
        title: 'ASSAULT IN PROGRESS',
        location: { name: 'POLICE ST', lat: 43.7, lng: -79.4 },
        meta: {
            division: 'D31',
            call_type_code: 'ASLTPR',
            object_id: 123,
        },
        ...overrides,
    };
}

describe('police presentation', () => {
    it.each([
        ['ASSAULT IN PROGRESS', 'high'],
        ['VEHICLE COLLISION', 'medium'],
        ['SUSPICIOUS ACTIVITY', 'low'],
    ] as const)('derives severity %s -> %s', (title, expected) => {
        expect(derivePoliceSeverity(makePoliceAlert({ title }))).toBe(expected);
    });

    it('builds police description and metadata', () => {
        const result = buildPoliceDescriptionAndMetadata(makePoliceAlert());

        expect(result.description).toBe('Call #123. Division: D31 • Code: ASLTPR.');
        expect(result.metadata).toEqual({
            eventNum: '123',
            alarmLevel: 0,
            unitsDispatched: null,
            beat: 'D31',
            source: 'Toronto Police',
        });
    });

    it('falls back to compact description when optional fields are null', () => {
        const result = buildPoliceDescriptionAndMetadata(
            makePoliceAlert({
                externalId: '999',
                meta: {
                    object_id: 0,
                    division: null,
                    call_type_code: null,
                },
            }),
        );

        expect(result.description).toBe('Call #999.');
    });
});
