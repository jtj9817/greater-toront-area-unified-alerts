import { describe, expect, it, vi } from 'vitest';
import {
    mapDomainAlertToPresentation,
    type UnifiedAlertResource,
} from '../domain/alerts';
import { AlertService } from './AlertService';

describe('AlertService', () => {
    const timestamp = new Date('2026-02-03T12:00:00Z').toISOString();

    const mockFireAlert: UnifiedAlertResource = {
        id: 'fire:E1',
        source: 'fire',
        external_id: 'E1',
        is_active: true,
        timestamp,
        title: 'STRUCTURE FIRE',
        location: { name: 'MAIN ST / CROSS RD', lat: null, lng: null },
        meta: {
            alarm_level: 2,
            units_dispatched: 'P1, P2, A1',
            beat: 'B1',
            event_num: 'E1',
        },
    };

    const mockPoliceAlert: UnifiedAlertResource = {
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

    const mockGoTransitAlert: UnifiedAlertResource = {
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
            corridor_code: 'LE',
            sub_category: 'TDELAY',
            direction: 'Eastbound',
            trip_number: null,
            delay_duration: '00:15:00',
            line_colour: null,
            message_body: null,
        },
    };

    it('maps a backend unified fire alert to DomainAlert correctly', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        const domainAlert =
            AlertService.mapUnifiedAlertToDomainAlert(mockFireAlert);

        expect(domainAlert).not.toBeNull();
        if (!domainAlert) throw new Error('Expected DomainAlert');

        const presentation = mapDomainAlertToPresentation(domainAlert);

        expect(domainAlert.id).toBe('fire:E1');
        expect(domainAlert.kind).toBe('fire');
        expect(presentation.type).toBe('fire');
        expect(presentation.severity).toBe('high');
        expect(presentation.iconName).toBe('local_fire_department');
        expect(presentation.metadata?.eventNum).toBe('E1');
        expect(presentation.metadata?.alarmLevel).toBe(2);
        expect(presentation.metadata?.source).toBe('Toronto Fire Services');
        expect(warn).not.toHaveBeenCalled();
        warn.mockRestore();
    });

    it('maps a backend unified police alert to DomainAlert correctly', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        const domainAlert =
            AlertService.mapUnifiedAlertToDomainAlert(mockPoliceAlert);

        expect(domainAlert).not.toBeNull();
        if (!domainAlert) throw new Error('Expected DomainAlert');

        const presentation = mapDomainAlertToPresentation(domainAlert);

        expect(domainAlert.kind).toBe('police');
        expect(presentation.type).toBe('police');
        expect(presentation.severity).toBe('high');
        expect(presentation.location).toBe('456 POLICE RD');
        expect(presentation.metadata?.eventNum).toBe('123');
        expect(presentation.metadata?.beat).toBe('D31');
        expect(presentation.metadata?.source).toBe('Toronto Police');
        expect(warn).not.toHaveBeenCalled();
        warn.mockRestore();
    });

    it('maps a backend unified transit alert to DomainAlert and preserves presentation fields', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        const mockTransitAlert: UnifiedAlertResource = {
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
                direction: 'Both Ways',
                url: null,
                cause: null,
                stop_start: 'St Clair',
                stop_end: 'Lawrence',
            },
        };

        const domainAlert =
            AlertService.mapUnifiedAlertToDomainAlert(mockTransitAlert);

        expect(domainAlert).not.toBeNull();
        if (!domainAlert) throw new Error('Expected DomainAlert');

        const presentation = mapDomainAlertToPresentation(domainAlert);

        expect(domainAlert.kind).toBe('transit');
        expect(presentation.type).toBe('transit');
        expect(presentation.severity).toBe('high');
        expect(presentation.iconName).toBe('directions_subway');
        expect(presentation.location).toBe('St Clair Station');
        expect(presentation.description).toContain('Subway 1');
        expect(presentation.description).toContain('Reduced service');
        expect(presentation.description).toContain(
            'Shuttle buses operating between St Clair and Lawrence.',
        );
        expect(presentation.metadata?.source).toBe('TTC Control');
        expect(presentation.metadata?.routeType).toBe('Subway');
        expect(presentation.metadata?.route).toBe('1');
        expect(presentation.metadata?.effect).toBe('REDUCED_SERVICE');
        expect(presentation.metadata?.direction).toBe('Both Ways');
        expect(presentation.metadata?.sourceFeed).toBe('live-api');
        expect(warn).not.toHaveBeenCalled();
        warn.mockRestore();
    });

    it('maps backend unified alerts to DomainAlert values and discards invalid resources', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const invalidFire = {
            ...mockFireAlert,
            id: 'fire:BAD',
            external_id: 'BAD',
            meta: {
                ...mockFireAlert.meta,
                alarm_level: 'bad-value',
            },
        } as unknown as UnifiedAlertResource;

        const alerts = AlertService.mapUnifiedAlertsToDomainAlerts([
            mockFireAlert,
            mockPoliceAlert,
            mockGoTransitAlert,
            invalidFire,
        ]);

        expect(alerts).toHaveLength(3);
        expect(alerts.map((alert) => alert.kind).sort()).toEqual([
            'fire',
            'go_transit',
            'police',
        ]);
        expect(warn).toHaveBeenCalled();
        warn.mockRestore();
    });

    it('returns null (and warns) for invalid unified alert resources', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const invalid: UnifiedAlertResource = {
            id: 'fire:invalid',
            source: 'fire',
            external_id: 'invalid',
            is_active: true,
            timestamp,
            title: 'STRUCTURE FIRE',
            location: { name: 'MAIN ST', lat: null, lng: null },
            meta: {
                alarm_level: 'not-a-number',
                event_num: 'invalid',
                units_dispatched: null,
                beat: null,
            },
        };

        expect(AlertService.mapUnifiedAlertToDomainAlert(invalid)).toBeNull();
        expect(warn).toHaveBeenCalled();
        warn.mockRestore();
    });
});
