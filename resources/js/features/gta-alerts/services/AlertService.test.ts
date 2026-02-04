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
            id: 'transit:T1',
            source: 'transit',
            external_id: 'T1',
            is_active: true,
            timestamp,
            title: 'Line 1 Delay',
            location: { name: 'St Clair Station', lat: 43.7, lng: -79.4 },
            meta: {
                estimated_delay: '20-30 mins',
                shuttle_info: 'Shuttle buses operating between St Clair and Lawrence.',
            },
        };

        const alertItem =
            AlertService.mapUnifiedAlertToAlertItem(mockTransitAlert);

        expect(alertItem.type).toBe('transit');
        expect(alertItem.location).toBe('St Clair Station');
        expect(alertItem.metadata?.source).toBe('TTC Control');
        expect(alertItem.metadata?.estimatedDelay).toBe('20-30 mins');
        expect(alertItem.metadata?.shuttleInfo).toBe(
            'Shuttle buses operating between St Clair and Lawrence.',
        );
    });

    it('maps transit alert without optional metadata correctly', () => {
        const mockTransitAlertMinimal: UnifiedAlertResource = {
            id: 'transit:T2',
            source: 'transit',
            external_id: 'T2',
            is_active: true,
            timestamp,
            title: 'Minor Delay',
            location: { name: 'Union Station', lat: null, lng: null },
            meta: {},
        };

        const alertItem =
            AlertService.mapUnifiedAlertToAlertItem(mockTransitAlertMinimal);

        expect(alertItem.type).toBe('transit');
        expect(alertItem.metadata?.source).toBe('TTC Control');
        expect(alertItem.metadata?.estimatedDelay).toBeUndefined();
        expect(alertItem.metadata?.shuttleInfo).toBeUndefined();
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
