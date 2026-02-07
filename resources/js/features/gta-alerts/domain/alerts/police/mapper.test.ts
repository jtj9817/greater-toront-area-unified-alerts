import { describe, it, expect, vi } from 'vitest';

import type { UnifiedAlertResourceParsed } from '../resource';
import { mapPoliceAlert } from './mapper';

describe('mapPoliceAlert', () => {
    const timestamp = new Date('2026-02-03T12:00:00Z').toISOString();

    it('maps a valid police resource to a PoliceAlert', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const resource: UnifiedAlertResourceParsed = {
            id: 'police:123',
            source: 'police',
            external_id: '123',
            is_active: true,
            timestamp,
            title: 'ASSAULT IN PROGRESS',
            location: { name: '456 POLICE RD', lat: 43.7, lng: -79.4 },
            meta: {
                division: 'D31',
                call_type_code: 'ASLTPR',
                object_id: 123,
            },
        };

        const alert = mapPoliceAlert(resource);
        expect(alert).not.toBeNull();
        expect(alert?.kind).toBe('police');
        expect(alert?.meta.object_id).toBe(123);

        warn.mockRestore();
    });

    it('returns null (and warns) for invalid police meta', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const resource: UnifiedAlertResourceParsed = {
            id: 'police:invalid',
            source: 'police',
            external_id: 'invalid',
            is_active: true,
            timestamp,
            title: 'THEFT',
            location: { name: '456 POLICE RD', lat: 43.7, lng: -79.4 },
            meta: {
                division: null,
                call_type_code: null,
                object_id: '123',
            },
        };

        expect(mapPoliceAlert(resource)).toBeNull();
        expect(warn).toHaveBeenCalled();

        warn.mockRestore();
    });
});

