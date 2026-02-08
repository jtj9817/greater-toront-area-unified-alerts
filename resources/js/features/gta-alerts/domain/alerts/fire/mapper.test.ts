import { describe, it, expect, vi } from 'vitest';

import type { UnifiedAlertResourceParsed } from '../resource';
import { mapFireAlert } from './mapper';

describe('mapFireAlert', () => {
    const timestamp = new Date('2026-02-03T12:00:00Z').toISOString();

    it('maps a valid fire resource to a FireAlert', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const resource: UnifiedAlertResourceParsed = {
            id: 'fire:E1',
            source: 'fire',
            external_id: 'E1',
            is_active: true,
            timestamp,
            title: 'STRUCTURE FIRE',
            location: { name: 'MAIN ST / CROSS RD', lat: null, lng: null },
            meta: {
                alarm_level: 2,
                event_num: 'E1',
                units_dispatched: null,
                beat: null,
            },
        };

        const alert = mapFireAlert(resource);
        expect(alert).not.toBeNull();
        expect(alert?.kind).toBe('fire');
        expect(alert?.meta.event_num).toBe('E1');

        warn.mockRestore();
    });

    it('returns null (and warns) for invalid fire meta', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const resource: UnifiedAlertResourceParsed = {
            id: 'fire:E2',
            source: 'fire',
            external_id: 'E2',
            is_active: true,
            timestamp,
            title: 'STRUCTURE FIRE',
            location: { name: 'MAIN ST / CROSS RD', lat: null, lng: null },
            meta: {
                alarm_level: '2',
                event_num: 'E2',
                units_dispatched: null,
                beat: null,
            },
        };

        expect(mapFireAlert(resource)).toBeNull();
        expect(warn).toHaveBeenCalled();

        warn.mockRestore();
    });
});
