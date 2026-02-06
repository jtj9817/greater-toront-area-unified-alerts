import { describe, it, expect } from 'vitest';
import type { UnifiedAlertResource } from '../types';
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
            service_mode: 'Train',
            corridor_code: 'LE',
            sub_category: 'TDELAY',
            direction: 'Eastbound',
            delay_duration: '00:15:00',
        },
    };

    it('maps a backend unified fire alert to an AlertItem correctly', () => {
        const alertItem =
            AlertService.mapUnifiedAlertToAlertItem(mockFireAlert);

        expect(alertItem.id).toBe('fire:E1');
        expect(alertItem.title).toBe('STRUCTURE FIRE');
        expect(alertItem.location).toBe('MAIN ST / CROSS RD');
        expect(alertItem.type).toBe('fire');
        expect(alertItem.severity).toBe('high');
        expect(alertItem.iconName).toBe('local_fire_department');
        expect(alertItem.metadata?.eventNum).toBe('E1');
        expect(alertItem.metadata?.alarmLevel).toBe(2);
        expect(alertItem.metadata?.source).toBe('Toronto Fire Services');
    });

    it('maps a backend unified police alert to an AlertItem correctly', () => {
        const alertItem =
            AlertService.mapUnifiedAlertToAlertItem(mockPoliceAlert);

        expect(alertItem.type).toBe('police');
        expect(alertItem.severity).toBe('high');
        expect(alertItem.location).toBe('456 POLICE RD');
        expect(alertItem.metadata?.eventNum).toBe('123');
        expect(alertItem.metadata?.beat).toBe('D31');
        expect(alertItem.metadata?.source).toBe('Toronto Police');
    });

    it('maps a backend unified transit alert to an AlertItem correctly', () => {
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
                description:
                    'Shuttle buses operating between St Clair and Lawrence.',
                direction: 'Both Ways',
                stop_start: 'St Clair',
                stop_end: 'Lawrence',
                source_feed: 'live-api',
            },
        };

        const alertItem =
            AlertService.mapUnifiedAlertToAlertItem(mockTransitAlert);

        expect(alertItem.type).toBe('transit');
        expect(alertItem.severity).toBe('high');
        expect(alertItem.iconName).toBe('directions_subway');
        expect(alertItem.location).toBe('St Clair Station');
        expect(alertItem.description).toContain('Subway 1');
        expect(alertItem.description).toContain('Reduced service');
        expect(alertItem.description).toContain(
            'Shuttle buses operating between St Clair and Lawrence.',
        );
        expect(alertItem.metadata?.source).toBe('TTC Control');
        expect(alertItem.metadata?.routeType).toBe('Subway');
        expect(alertItem.metadata?.route).toBe('1');
        expect(alertItem.metadata?.effect).toBe('REDUCED_SERVICE');
        expect(alertItem.metadata?.direction).toBe('Both Ways');
        expect(alertItem.metadata?.sourceFeed).toBe('live-api');
    });

    it('maps transit alert with minimal metadata correctly', () => {
        const mockTransitAlertMinimal: UnifiedAlertResource = {
            id: 'transit:T2',
            source: 'transit',
            external_id: 'T2',
            is_active: true,
            timestamp,
            title: 'Minor route detour',
            location: { name: 'Union Station', lat: null, lng: null },
            meta: {
                route_type: 'Bus',
                route: '52',
                severity: 'Minor',
                effect: 'DETOUR',
            },
        };

        const alertItem = AlertService.mapUnifiedAlertToAlertItem(
            mockTransitAlertMinimal,
        );

        expect(alertItem.type).toBe('transit');
        expect(alertItem.severity).toBe('medium');
        expect(alertItem.iconName).toBe('directions_bus');
        expect(alertItem.description).toContain('Bus 52');
        expect(alertItem.description).toContain('Detour in effect');
        expect(alertItem.metadata?.source).toBe('TTC Control');
    });

    it.each([
        {
            routeType: 'Streetcar',
            effect: 'SIGNIFICANT_DELAYS',
            expectedIcon: 'tram',
        },
        {
            routeType: 'Elevator',
            effect: 'ACCESSIBILITY_ISSUE',
            expectedIcon: 'elevator',
        },
    ])(
        'maps transit route type $routeType to icon $expectedIcon',
        ({ routeType, effect, expectedIcon }) => {
            const alertItem = AlertService.mapUnifiedAlertToAlertItem({
                id: `transit:${routeType}`,
                source: 'transit',
                external_id: `x-${routeType}`,
                is_active: true,
                timestamp,
                title: `${routeType} alert`,
                location: { name: 'TTC', lat: null, lng: null },
                meta: {
                    route_type: routeType,
                    effect,
                },
            });

            expect(alertItem.iconName).toBe(expectedIcon);
        },
    );

    it('maps transit alert to low severity when no critical severity or known effect exists', () => {
        const alertItem = AlertService.mapUnifiedAlertToAlertItem({
            id: 'transit:low-severity',
            source: 'transit',
            external_id: 'low-severity',
            is_active: true,
            timestamp,
            title: 'Transit notice',
            location: { name: 'TTC', lat: null, lng: null },
            meta: {
                severity: 'Minor',
                effect: 'OTHER_EFFECT',
            },
        });

        expect(alertItem.severity).toBe('low');
    });

    it('keeps GO Transit alerts reachable via transit category filter', () => {
        const items = [
            AlertService.mapUnifiedAlertToAlertItem(mockFireAlert),
            AlertService.mapUnifiedAlertToAlertItem({
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
                    effect: 'SIGNIFICANT_DELAYS',
                },
            }),
            AlertService.mapUnifiedAlertToAlertItem(mockGoTransitAlert),
        ];

        const results = AlertService.search(items, {
            query: '',
            category: 'transit',
            dateScope: 'all',
        });

        expect(results).toHaveLength(2);
        expect(results.map((item) => item.type).sort()).toEqual([
            'go_transit',
            'transit',
        ]);
    });

    it('filters items by search query', () => {
        const items = [
            AlertService.mapUnifiedAlertToAlertItem(mockFireAlert),
            AlertService.mapUnifiedAlertToAlertItem({
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
            }),
        ];

        const results = AlertService.search(items, {
            query: 'GAS',
            category: 'all',
            dateScope: 'all',
        });

        expect(results).toHaveLength(1);
        expect(results[0].title).toBe('GAS LEAK');
    });

    it('filters items by category', () => {
        const items = [
            AlertService.mapUnifiedAlertToAlertItem(mockFireAlert),
            AlertService.mapUnifiedAlertToAlertItem({
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
            }),
        ];

        const results = AlertService.search(items, {
            query: '',
            category: 'hazard',
            dateScope: 'all',
        });

        expect(results).toHaveLength(1);
        expect(results[0].title).toBe('GAS LEAK');
    });
});
