import { describe, expect, it } from 'vitest';
import { formatTimeAgo } from '@/lib/utils';

import type { FireAlert } from '../fire/schema';
import type { PoliceAlert } from '../police/schema';
import type { GoTransitAlert } from '../transit/go/schema';
import type { TtcTransitAlert } from '../transit/ttc/schema';
import type { YrtAlert } from '../transit/yrt/schema';
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

function makeYrtAlert(): YrtAlert {
    return {
        kind: 'yrt',
        id: 'yrt:Y1',
        externalId: 'Y1',
        isActive: true,
        timestamp: new Date('2026-02-03T12:00:00Z').toISOString(),
        title: '52 - Holland Landing detour',
        location: { name: '52', lat: null, lng: null },
        meta: {
            details_url: 'https://www.yrt.ca/en/service-updates/91001.aspx',
            description_excerpt: 'Temporary detour in effect near Green Lane.',
            body_text: 'Routes affected: 52, 58. Expect 15 minute delays.',
            posted_at: '2026-02-03 07:00:00',
            feed_updated_at: '2026-02-03 07:05:00',
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
        expect(alertItem.iconColor).toBe('text-hazard');
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
        expect(alertItem.iconName).toBe('directions_transit');
        expect(alertItem.metadata?.source).toBe('GO Transit');
    });

    it('maps YRT domain alert through shared transit presentation styling', () => {
        const alertItem = mapDomainAlertToPresentation(makeYrtAlert());

        expect(alertItem.type).toBe('transit');
        expect(alertItem.severity).toBe('medium');
        expect(alertItem.iconName).toBe('directions_bus');
        expect(alertItem.metadata?.source).toBe('YRT');
    });

    it('falls back to Unknown location when domain alert location is null', () => {
        const fire = makeFireAlert();
        fire.location = null;

        const alertItem = mapDomainAlertToPresentation(fire);

        expect(alertItem.location).toBe('Unknown location');
    });

    it('preserves valid police coordinates as locationCoords', () => {
        const police = makePoliceAlert();
        police.location = { name: 'Yonge & Bloor', lat: 43.67, lng: -79.4 };

        const alertItem = mapDomainAlertToPresentation(police);

        expect(alertItem.locationCoords).toEqual({
            lat: 43.67,
            lng: -79.4,
        });
        expect(alertItem.location).toBe('Yonge & Bloor');
    });

    it('preserves coordinates that sit exactly on GTA boundary limits', () => {
        const police = makePoliceAlert();
        police.location = { name: 'Boundary', lat: 40, lng: -90 };

        const minBoundaryAlert = mapDomainAlertToPresentation(police);

        expect(minBoundaryAlert.locationCoords).toEqual({
            lat: 40,
            lng: -90,
        });

        police.location = { name: 'Boundary', lat: 50, lng: -70 };

        const maxBoundaryAlert = mapDomainAlertToPresentation(police);

        expect(maxBoundaryAlert.locationCoords).toEqual({
            lat: 50,
            lng: -70,
        });
    });

    it('sets locationCoords to null when location is null', () => {
        const fire = makeFireAlert();
        fire.location = null;

        const alertItem = mapDomainAlertToPresentation(fire);

        expect(alertItem.locationCoords).toBeNull();
    });

    it('sets locationCoords to null when both lat and lng are null', () => {
        const fire = makeFireAlert();
        fire.location = { name: 'MAIN ST', lat: null, lng: null };

        const alertItem = mapDomainAlertToPresentation(fire);

        expect(alertItem.locationCoords).toBeNull();
    });

    it('sets locationCoords to null when lat is present but lng is null (partial)', () => {
        const police = makePoliceAlert();
        police.location = { name: 'Partial', lat: 43.67, lng: null };

        const alertItem = mapDomainAlertToPresentation(police);

        expect(alertItem.locationCoords).toBeNull();
    });

    it('sets locationCoords to null when lng is present but lat is null (partial)', () => {
        const police = makePoliceAlert();
        police.location = { name: 'Partial', lat: null, lng: -79.4 };

        const alertItem = mapDomainAlertToPresentation(police);

        expect(alertItem.locationCoords).toBeNull();
    });

    it('sets locationCoords to null for NaN coordinates (non-finite)', () => {
        const police = makePoliceAlert();
        police.location = { name: 'Bad', lat: Number.NaN, lng: -79.4 };

        const alertItem = mapDomainAlertToPresentation(police);

        expect(alertItem.locationCoords).toBeNull();
    });

    it('sets locationCoords to null for Infinity coordinates (non-finite)', () => {
        const police = makePoliceAlert();
        police.location = {
            name: 'Inf',
            lat: 43.67,
            lng: Number.POSITIVE_INFINITY,
        };

        const alertItem = mapDomainAlertToPresentation(police);

        expect(alertItem.locationCoords).toBeNull();
    });

    it('sets locationCoords to null for out-of-range latitude (too far north)', () => {
        const police = makePoliceAlert();
        police.location = { name: 'Arctic', lat: 55.0, lng: -79.4 };

        const alertItem = mapDomainAlertToPresentation(police);

        expect(alertItem.locationCoords).toBeNull();
    });

    it('sets locationCoords to null for out-of-range latitude (too far south)', () => {
        const police = makePoliceAlert();
        police.location = { name: 'Florida', lat: 25.0, lng: -79.4 };

        const alertItem = mapDomainAlertToPresentation(police);

        expect(alertItem.locationCoords).toBeNull();
    });

    it('sets locationCoords to null for out-of-range longitude (too far west)', () => {
        const police = makePoliceAlert();
        police.location = { name: 'Manitoba', lat: 43.67, lng: -100.0 };

        const alertItem = mapDomainAlertToPresentation(police);

        expect(alertItem.locationCoords).toBeNull();
    });

    it('sets locationCoords to null for out-of-range longitude (too far east)', () => {
        const police = makePoliceAlert();
        police.location = { name: 'Maritimes', lat: 43.67, lng: -60.0 };

        const alertItem = mapDomainAlertToPresentation(police);

        expect(alertItem.locationCoords).toBeNull();
    });

    it('sets locationCoords to null for Null Island (0, 0) coordinates', () => {
        const police = makePoliceAlert();
        police.location = { name: 'Null Island', lat: 0, lng: 0 };

        const alertItem = mapDomainAlertToPresentation(police);

        expect(alertItem.locationCoords).toBeNull();
    });

    it('preserves location label even when locationCoords is null', () => {
        const fire = makeFireAlert();
        fire.location = { name: '  MAIN ST  ', lat: null, lng: null };

        const alertItem = mapDomainAlertToPresentation(fire);

        expect(alertItem.locationCoords).toBeNull();
        expect(alertItem.location).toBe('MAIN ST');
    });
});
