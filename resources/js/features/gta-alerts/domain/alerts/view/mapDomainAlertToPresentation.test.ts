import { describe, expect, it } from 'vitest';
import { formatTimeAgo } from '@/lib/utils';

import type { FireAlert } from '../fire/schema';
import type { PoliceAlert } from '../police/schema';
import type { GoTransitAlert } from '../transit/go/schema';
import type { TtcTransitAlert } from '../transit/ttc/schema';
import { mapDomainAlertToPresentation } from './mapDomainAlertToPresentation';

function makeFireAlert(title = 'GAS LEAK'): FireAlert {
    return {
        kind: 'fire',
        id: 'fire:E1',
        externalId: 'E1',
        isActive: true,
        timestamp: new Date('2026-02-03T12:00:00Z').toISOString(),
        title,
        location: { name: 'MAIN ST', lat: null, lng: null },
        meta: {
            alarm_level: 2,
            event_num: 'E1',
            units_dispatched: null,
            beat: null,
            intel_summary: [],
            intel_last_updated: null,
        },
    };
}

function makePoliceAlert(): PoliceAlert {
    return {
        kind: 'police',
        id: 'police:123',
        externalId: '123',
        isActive: true,
        timestamp: new Date('2026-02-03T12:00:00Z').toISOString(),
        title: 'ASSAULT IN PROGRESS',
        location: { name: 'POLICE ST', lat: null, lng: null },
        meta: {
            division: 'D31',
            call_type_code: 'ASLTPR',
            object_id: 123,
        },
    };
}

function makeTransitAlert(): TtcTransitAlert {
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
            description: 'Shuttle buses operating.',
            url: null,
            cause: null,
            stop_start: 'St Clair',
            stop_end: 'Lawrence',
        },
    };
}

function makeGoAlert(): GoTransitAlert {
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
            message_body: null,
        },
    };
}

describe('mapDomainAlertToPresentation', () => {
    it('maps fire domain alert using derived hazard type and high severity styling', () => {
        const alertItem = mapDomainAlertToPresentation(makeFireAlert());

        expect(alertItem.type).toBe('hazard');
        expect(alertItem.severity).toBe('high');
        expect(alertItem.iconName).toBe('warning');
        expect(alertItem.accentColor).toBe('bg-critical');
        expect(alertItem.iconColor).toBe('text-primary');
        expect(alertItem.timeAgo).toBe(
            formatTimeAgo(makeFireAlert().timestamp),
        );
    });

    it('maps police domain alert preserving metadata', () => {
        const alertItem = mapDomainAlertToPresentation(makePoliceAlert());

        expect(alertItem.type).toBe('police');
        expect(alertItem.severity).toBe('high');
        expect(alertItem.metadata?.beat).toBe('D31');
        expect(alertItem.metadata?.source).toBe('Toronto Police');
    });

    it('maps TTC transit domain alert with route-specific icon', () => {
        const alertItem = mapDomainAlertToPresentation(makeTransitAlert());

        expect(alertItem.type).toBe('transit');
        expect(alertItem.severity).toBe('high');
        expect(alertItem.iconName).toBe('directions_subway');
        expect(alertItem.metadata?.source).toBe('TTC Control');
    });

    it('maps GO transit domain alert with GO-specific metadata', () => {
        const alertItem = mapDomainAlertToPresentation(makeGoAlert());

        expect(alertItem.type).toBe('go_transit');
        expect(alertItem.severity).toBe('medium');
        expect(alertItem.iconName).toBe('train');
        expect(alertItem.metadata?.source).toBe('GO Transit');
    });

    it('falls back to Unknown location when domain alert location is null', () => {
        const fire = makeFireAlert();
        fire.location = null;

        const alertItem = mapDomainAlertToPresentation(fire);

        expect(alertItem.location).toBe('Unknown location');
    });
});
