import { describe, it, expect, vi } from 'vitest';

import type { UnifiedAlertResourceParsed } from '../../resource';
import { mapGoTransitAlert } from './mapper';

describe('mapGoTransitAlert', () => {
    const timestamp = new Date('2026-02-03T12:00:00Z').toISOString();

    it('maps a valid GO Transit resource to a GoTransitAlert', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const resource: UnifiedAlertResourceParsed = {
            id: 'go_transit:12345',
            source: 'go_transit',
            external_id: '12345',
            is_active: true,
            timestamp,
            title: 'Lakeshore East delay',
            location: { name: 'Union Station', lat: 43.7, lng: -79.4 },
            meta: {
                alert_type: 'saag',
                service_mode: 'Train',
                sub_category: 'TDELAY',
                corridor_code: 'LE',
                direction: 'Eastbound',
                trip_number: null,
                delay_duration: '00:15:00',
                line_colour: null,
                message_body: null,
            },
        };

        const alert = mapGoTransitAlert(resource);
        expect(alert).not.toBeNull();
        expect(alert?.kind).toBe('go_transit');
        expect(alert?.meta.corridor_code).toBe('LE');

        warn.mockRestore();
    });

    it('returns null (and warns) for invalid GO Transit meta', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const resource: UnifiedAlertResourceParsed = {
            id: 'go_transit:invalid',
            source: 'go_transit',
            external_id: 'invalid',
            is_active: true,
            timestamp,
            title: 'Delay',
            location: { name: 'Union Station', lat: 43.7, lng: -79.4 },
            meta: {
                alert_type: null,
                service_mode: 'Train',
                sub_category: 'TDELAY',
                corridor_code: 'LE',
                direction: 'Eastbound',
                trip_number: null,
                delay_duration: 123,
                line_colour: null,
                message_body: null,
            },
        };

        expect(mapGoTransitAlert(resource)).toBeNull();
        expect(warn).toHaveBeenCalled();

        warn.mockRestore();
    });
});

