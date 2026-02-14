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

    it('accepts offset timestamps in scene intel metadata', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const resource: UnifiedAlertResourceParsed = {
            id: 'fire:E3',
            source: 'fire',
            external_id: 'E3',
            is_active: true,
            timestamp,
            title: 'STRUCTURE FIRE',
            location: { name: 'MAIN ST / CROSS RD', lat: null, lng: null },
            meta: {
                alarm_level: 2,
                event_num: 'E3',
                units_dispatched: 'P1',
                beat: 'B1',
                intel_last_updated: '2026-02-14T09:28:21+00:00',
                intel_summary: [
                    {
                        id: 1,
                        type: 'milestone',
                        type_label: 'Milestone',
                        icon: 'flag',
                        content: 'Command established',
                        timestamp: '2026-02-14T09:28:21+00:00',
                        metadata: null,
                    },
                ],
            },
        };

        const alert = mapFireAlert(resource);
        expect(alert).not.toBeNull();
        expect(alert?.meta.intel_last_updated).toBe(
            '2026-02-14T09:28:21+00:00',
        );
        expect(alert?.meta.intel_summary[0]?.timestamp).toBe(
            '2026-02-14T09:28:21+00:00',
        );

        warn.mockRestore();
    });
});
