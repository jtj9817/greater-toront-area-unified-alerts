import { describe, it, expect, vi } from 'vitest';

import type { UnifiedAlertResourceParsed } from '../../resource';
import { mapTtcTransitAlert } from './mapper';

describe('mapTtcTransitAlert', () => {
    const timestamp = new Date('2026-02-03T12:00:00Z').toISOString();

    it('maps a valid transit resource to a TtcTransitAlert', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const resource: UnifiedAlertResourceParsed = {
            id: 'transit:api:61748',
            source: 'transit',
            external_id: 'api:61748',
            is_active: true,
            timestamp,
            title: 'Line 1 Yonge-University delay',
            location: { name: 'St Clair Station', lat: 43.7, lng: -79.4 },
            meta: {
                route_type: 'Subway',
                route: '1',
                severity: 'Critical',
                effect: 'REDUCED_SERVICE',
                source_feed: 'live-api',
                alert_type: 'advisory',
                description:
                    'Shuttle buses operating between St Clair and Lawrence.',
                url: null,
                direction: 'Both Ways',
                cause: null,
                stop_start: 'St Clair',
                stop_end: 'Lawrence',
            },
        };

        const alert = mapTtcTransitAlert(resource);
        expect(alert).not.toBeNull();
        expect(alert?.kind).toBe('transit');
        expect(alert?.meta.route_type).toBe('Subway');

        warn.mockRestore();
    });

    it('returns null (and warns) for invalid transit meta', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const resource: UnifiedAlertResourceParsed = {
            id: 'transit:invalid',
            source: 'transit',
            external_id: 'invalid',
            is_active: true,
            timestamp,
            title: 'Line 1 delay',
            location: { name: 'St Clair Station', lat: 43.7, lng: -79.4 },
            meta: {
                route_type: 'Subway',
                route: '1',
                severity: 'Critical',
                effect: 123,
                source_feed: 'live-api',
                alert_type: 'advisory',
                description: null,
                url: null,
                direction: 'Both Ways',
                cause: null,
                stop_start: null,
                stop_end: null,
            },
        };

        expect(mapTtcTransitAlert(resource)).toBeNull();
        expect(warn).toHaveBeenCalled();

        warn.mockRestore();
    });
});

