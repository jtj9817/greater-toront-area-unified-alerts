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

    it('keeps GO Transit alerts reachable via transit category filter when searching DomainAlert values', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        const items = AlertService.mapUnifiedAlertsToDomainAlerts([
            mockFireAlert,
            {
                id: 'transit:T1',
                source: 'transit',
                external_id: 'T1',
                is_active: true,
                timestamp,
                title: 'Line 2 delay',
                location: { name: 'Bloor Station', lat: null, lng: null },
                meta: {
                    route_type: 'Subway',
                    route: '2',
                    severity: null,
                    effect: 'SIGNIFICANT_DELAYS',
                    source_feed: 'live-api',
                    alert_type: 'advisory',
                    description: null,
                    url: null,
                    direction: null,
                    cause: null,
                    stop_start: null,
                    stop_end: null,
                },
            },
            mockGoTransitAlert,
        ]);

        const results = AlertService.searchDomainAlerts(items, {
            query: '',
            category: 'transit',
            dateScope: 'all',
        });

        expect(results).toHaveLength(2);
        expect(results.map((item) => item.kind).sort()).toEqual([
            'go_transit',
            'transit',
        ]);
        expect(warn).not.toHaveBeenCalled();
        warn.mockRestore();
    });

    it('filters DomainAlert items by search query', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        const items = AlertService.mapUnifiedAlertsToDomainAlerts([
            mockFireAlert,
            {
                ...mockFireAlert,
                id: 'fire:E2',
                external_id: 'E2',
                title: 'GAS LEAK',
                location: { name: 'GAS ST', lat: null, lng: null },
                meta: {
                    ...mockFireAlert.meta,
                    alarm_level: 0,
                    event_num: 'E2',
                },
            },
        ]);

        const results = AlertService.searchDomainAlerts(items, {
            query: 'GAS',
            category: 'all',
            dateScope: 'all',
        });

        expect(results).toHaveLength(1);
        expect(results[0].title).toBe('GAS LEAK');
        expect(warn).not.toHaveBeenCalled();
        warn.mockRestore();
    });

    it('filters DomainAlert items by derived hazard category', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        const items = AlertService.mapUnifiedAlertsToDomainAlerts([
            mockFireAlert,
            {
                ...mockFireAlert,
                id: 'fire:E2',
                external_id: 'E2',
                title: 'GAS LEAK',
                location: { name: 'GAS ST', lat: null, lng: null },
                meta: {
                    ...mockFireAlert.meta,
                    alarm_level: 0,
                    event_num: 'E2',
                },
            },
        ]);

        const results = AlertService.searchDomainAlerts(items, {
            query: '',
            category: 'hazard',
            dateScope: 'all',
        });

        expect(results).toHaveLength(1);
        expect(results[0].title).toBe('GAS LEAK');
        expect(warn).not.toHaveBeenCalled();
        warn.mockRestore();
    });

    it('sorts search results by timestamp descending', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        const nowIso = new Date().toISOString();
        const olderIso = new Date(
            Date.now() - 3 * 60 * 60 * 1000,
        ).toISOString();

        const items = AlertService.mapUnifiedAlertsToDomainAlerts([
            {
                ...mockFireAlert,
                id: 'fire:old',
                external_id: 'old',
                timestamp: olderIso,
            },
            {
                ...mockPoliceAlert,
                id: 'police:new',
                external_id: 'new',
                timestamp: nowIso,
            },
        ]);

        const results = AlertService.searchDomainAlerts(items, {
            query: '',
            category: 'all',
            dateScope: 'all',
        });

        expect(results).toHaveLength(2);
        expect(results[0].id).toBe('police:new');
        expect(results[1].id).toBe('fire:old');
        expect(warn).not.toHaveBeenCalled();
        warn.mockRestore();
    });

    it('applies timeLimit filter using parsed relative minutes', () => {
        const nowIso = new Date().toISOString();
        const twoHoursIso = new Date(
            Date.now() - 2 * 60 * 60 * 1000,
        ).toISOString();

        const items = AlertService.mapUnifiedAlertsToDomainAlerts([
            {
                ...mockFireAlert,
                id: 'fire:recent',
                external_id: 'recent',
                timestamp: nowIso,
            },
            {
                ...mockPoliceAlert,
                id: 'police:old',
                external_id: 'old',
                timestamp: twoHoursIso,
            },
        ]);

        const results = AlertService.searchDomainAlerts(items, {
            query: '',
            category: 'all',
            timeLimit: 60,
            dateScope: 'all',
        });

        expect(results).toHaveLength(1);
        expect(results[0].id).toBe('fire:recent');
    });

    it('applies dateScope filters for today and yesterday', () => {
        const nowIso = new Date().toISOString();
        const oneDayIso = new Date(
            Date.now() - 25 * 60 * 60 * 1000,
        ).toISOString();
        const twoDayIso = new Date(
            Date.now() - 49 * 60 * 60 * 1000,
        ).toISOString();

        const items = AlertService.mapUnifiedAlertsToDomainAlerts([
            {
                ...mockFireAlert,
                id: 'fire:today',
                external_id: 'today',
                timestamp: nowIso,
            },
            {
                ...mockPoliceAlert,
                id: 'police:yesterday',
                external_id: 'yesterday',
                timestamp: oneDayIso,
            },
            {
                ...mockGoTransitAlert,
                id: 'go_transit:twodays',
                external_id: 'twodays',
                timestamp: twoDayIso,
            },
        ]);

        const todayResults = AlertService.searchDomainAlerts(items, {
            query: '',
            category: 'all',
            dateScope: 'today',
        });

        const yesterdayResults = AlertService.searchDomainAlerts(items, {
            query: '',
            category: 'all',
            dateScope: 'yesterday',
        });

        expect(todayResults.map((item) => item.id)).toEqual(['fire:today']);
        expect(yesterdayResults.map((item) => item.id)).toEqual([
            'police:yesterday',
        ]);
    });

    it('matches search query across description, location, id, and type', () => {
        const items = AlertService.mapUnifiedAlertsToDomainAlerts([
            {
                ...mockFireAlert,
                id: 'fire:desc-match',
                external_id: 'desc-match',
                title: 'UNRELATED TITLE',
                meta: {
                    ...mockFireAlert.meta,
                    event_num: 'desc-match',
                },
            },
            {
                ...mockPoliceAlert,
                id: 'police:loc-match',
                external_id: 'loc-match',
                title: 'UNRELATED POLICE',
                location: { name: 'Queen Street West', lat: null, lng: null },
            },
            {
                ...mockGoTransitAlert,
                id: 'go_transit:id-match',
                external_id: 'id-match',
                title: 'UNRELATED GO',
            },
            {
                ...mockGoTransitAlert,
                id: 'go_transit:type-match',
                external_id: 'type-match',
                title: 'Another GO',
            },
        ]);

        const descResults = AlertService.searchDomainAlerts(items, {
            query: 'event #desc-match',
            category: 'all',
            dateScope: 'all',
        });
        const locResults = AlertService.searchDomainAlerts(items, {
            query: 'queen street west',
            category: 'all',
            dateScope: 'all',
        });
        const idResults = AlertService.searchDomainAlerts(items, {
            query: 'id-match',
            category: 'all',
            dateScope: 'all',
        });
        const typeResults = AlertService.searchDomainAlerts(items, {
            query: 'go_transit',
            category: 'all',
            dateScope: 'all',
        });

        expect(descResults.map((item) => item.id)).toEqual(['fire:desc-match']);
        expect(locResults.map((item) => item.id)).toEqual(['police:loc-match']);
        expect(idResults.map((item) => item.id)).toEqual([
            'go_transit:id-match',
        ]);
        expect(typeResults.map((item) => item.id).sort()).toEqual([
            'go_transit:id-match',
            'go_transit:type-match',
        ]);
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
