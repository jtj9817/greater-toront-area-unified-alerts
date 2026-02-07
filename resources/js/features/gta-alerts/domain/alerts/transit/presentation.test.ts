import { describe, expect, it } from 'vitest';

import type { GoTransitAlert } from './go/schema';
import {
    buildGoTransitDescriptionAndMetadata,
    buildTtcDescriptionAndMetadata,
    deriveGoTransitSeverity,
    deriveTtcIconName,
    deriveTtcSeverity,
    getTransitEffectLabel,
    getTransitRouteLabel,
} from './presentation';
import type { TtcTransitAlert } from './ttc/schema';

function makeTtcAlert(overrides: Partial<TtcTransitAlert> = {}): TtcTransitAlert {
    return {
        kind: 'transit',
        id: 'transit:T1',
        externalId: 'T1',
        isActive: true,
        timestamp: new Date('2026-02-03T12:00:00Z').toISOString(),
        title: 'Line 1 delay',
        location: { name: 'St Clair', lat: null, lng: null },
        meta: {
            alert_type: null,
            direction: 'Both Ways',
            route_type: 'Subway',
            route: '1',
            severity: 'Critical',
            effect: 'REDUCED_SERVICE',
            source_feed: 'live-api',
            description: 'Shuttle buses in place.',
            url: null,
            cause: null,
            stop_start: 'St Clair',
            stop_end: 'Lawrence',
        },
        ...overrides,
    };
}

function makeGoAlert(overrides: Partial<GoTransitAlert> = {}): GoTransitAlert {
    return {
        kind: 'go_transit',
        id: 'go_transit:G1',
        externalId: 'G1',
        isActive: true,
        timestamp: new Date('2026-02-03T12:00:00Z').toISOString(),
        title: 'Lakeshore East delay',
        location: { name: 'Union Station', lat: null, lng: null },
        meta: {
            alert_type: 'saag',
            direction: 'Eastbound',
            service_mode: 'Train',
            sub_category: 'TDELAY',
            corridor_code: 'LE',
            trip_number: null,
            delay_duration: '00:15:00',
            line_colour: null,
            message_body: 'Expect minor delays.',
        },
        ...overrides,
    };
}

describe('transit presentation', () => {
    it.each([
        [{ severity: 'Critical', effect: null }, 'high'],
        [{ severity: 'Minor', effect: 'DETOUR' }, 'medium'],
        [{ severity: 'Minor', effect: 'OTHER_EFFECT' }, 'low'],
    ] as const)('derives TTC severity %#', (metaOverrides, expected) => {
        const alert = makeTtcAlert({
            meta: {
                ...makeTtcAlert().meta,
                ...metaOverrides,
            },
        });

        expect(deriveTtcSeverity(alert.meta)).toBe(expected);
    });

    it.each([
        [{ sub_category: 'BCANCEL', alert_type: null }, 'high'],
        [{ sub_category: 'TDELAY', alert_type: null }, 'medium'],
        [{ sub_category: null, alert_type: 'saag' }, 'medium'],
        [{ sub_category: null, alert_type: null }, 'low'],
    ] as const)('derives GO severity %#', (metaOverrides, expected) => {
        const alert = makeGoAlert({
            meta: {
                ...makeGoAlert().meta,
                ...metaOverrides,
            },
        });

        expect(deriveGoTransitSeverity(alert.meta)).toBe(expected);
    });

    it.each([
        [{ route_type: 'Subway', effect: null }, 'directions_subway'],
        [{ route_type: 'Bus', effect: null }, 'directions_bus'],
        [{ route_type: 'Streetcar', effect: null }, 'tram'],
        [{ route_type: 'Unknown', effect: 'ACCESSIBILITY_ISSUE' }, 'elevator'],
        [{ route_type: null, effect: null }, 'train'],
    ] as const)('derives TTC icon %#', (metaOverrides, expected) => {
        const alert = makeTtcAlert({
            meta: {
                ...makeTtcAlert().meta,
                ...metaOverrides,
            },
        });

        expect(deriveTtcIconName(alert)).toBe(expected);
    });

    it('builds TTC labels and metadata details', () => {
        expect(getTransitRouteLabel('Bus', '52')).toBe('Bus 52');
        expect(getTransitRouteLabel(undefined, '52')).toBe('Route 52');
        expect(getTransitEffectLabel('REDUCED_SERVICE')).toBe('Reduced service');
        expect(getTransitEffectLabel('UNPLANNED_CLOSURE')).toBe('Unplanned Closure');

        const result = buildTtcDescriptionAndMetadata(makeTtcAlert());
        expect(result.description).toContain('Subway 1');
        expect(result.description).toContain('Reduced service');
        expect(result.description).toContain('Shuttle buses in place.');
        expect(result.metadata?.source).toBe('TTC Control');
        expect(result.metadata?.routeType).toBe('Subway');
    });

    it('builds GO description and metadata with fallback title when needed', () => {
        const detailed = buildGoTransitDescriptionAndMetadata(makeGoAlert());
        expect(detailed.description).toContain('Train');
        expect(detailed.description).toContain('Expect minor delays.');
        expect(detailed.metadata?.source).toBe('GO Transit');

        const fallback = buildGoTransitDescriptionAndMetadata(
            makeGoAlert({
                title: 'Fallback title',
                meta: {
                    ...makeGoAlert().meta,
                    service_mode: null,
                    corridor_code: null,
                    direction: null,
                    delay_duration: '00:00:00',
                    message_body: null,
                },
            }),
        );
        expect(fallback.description).toBe('Fallback title');
    });
});
