import { describe, expect, it } from 'vitest';

import type { FireAlert } from '../fire/schema';
import type { GoTransitAlert } from '../transit/go/schema';
import type { TtcTransitAlert } from '../transit/ttc/schema';
import {
    deriveAccentColor,
    deriveIconColor,
    deriveIconName,
} from './presentationStyles';

function makeFireAlert(title: string): FireAlert {
    return {
        kind: 'fire',
        id: 'fire:E1',
        externalId: 'E1',
        isActive: true,
        timestamp: new Date('2026-02-03T12:00:00Z').toISOString(),
        title,
        location: { name: 'Main', lat: null, lng: null },
        meta: {
            alarm_level: 1,
            event_num: 'E1',
            units_dispatched: null,
            beat: null,
        },
    };
}

function makeTransitAlert(
    routeType: string,
    effect = 'DETOUR',
): TtcTransitAlert {
    return {
        kind: 'transit',
        id: 'transit:T1',
        externalId: 'T1',
        isActive: true,
        timestamp: new Date('2026-02-03T12:00:00Z').toISOString(),
        title: 'Transit alert',
        location: { name: 'Station', lat: null, lng: null },
        meta: {
            alert_type: null,
            direction: null,
            route_type: routeType,
            route: null,
            severity: null,
            effect,
            source_feed: 'live-api',
            description: null,
            url: null,
            cause: null,
            stop_start: null,
            stop_end: null,
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
        title: 'GO alert',
        location: { name: 'Union', lat: null, lng: null },
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

describe('presentationStyles', () => {
    it('derives icon name from title keyword shortcuts', () => {
        expect(deriveIconName(makeFireAlert('GAS LEAK'), 'fire')).toBe(
            'warning',
        );
        expect(
            deriveIconName(makeFireAlert('MULTI CAR COLLISION'), 'police'),
        ).toBe('car_crash');
    });

    it('derives go_transit and transit route-specific icon names', () => {
        expect(deriveIconName(makeGoAlert(), 'go_transit')).toBe('train');
        expect(deriveIconName(makeTransitAlert('Bus'), 'transit')).toBe(
            'directions_bus',
        );
        expect(deriveIconName(makeTransitAlert('Streetcar'), 'transit')).toBe(
            'tram',
        );
        expect(
            deriveIconName(
                makeTransitAlert('Elevator', 'ACCESSIBILITY_ISSUE'),
                'transit',
            ),
        ).toBe('elevator');
    });

    it('derives icon name fallback for presentation categories', () => {
        const fire = makeFireAlert('STRUCTURE FIRE');

        expect(deriveIconName(fire, 'fire')).toBe('local_fire_department');
        expect(deriveIconName(fire, 'police')).toBe('shield');
        expect(deriveIconName(fire, 'hazard')).toBe('warning');
        expect(deriveIconName(fire, 'transit')).toBe('train');
        expect(deriveIconName(fire, 'medical')).toBe('medical_services');
        expect(deriveIconName(fire, 'unknown' as never)).toBe('info');
    });

    it('derives accent color for all severities and categories', () => {
        expect(deriveAccentColor('fire', 'high')).toBe('bg-[#e05560]');
        expect(deriveAccentColor('fire', 'medium')).toBe('bg-[#e07830]');
        expect(deriveAccentColor('police', 'medium')).toBe('bg-[#6890ff]');
        expect(deriveAccentColor('hazard', 'medium')).toBe('bg-[#f0b040]');
        expect(deriveAccentColor('transit', 'medium')).toBe('bg-[#a78bfa]');
        expect(deriveAccentColor('go_transit', 'medium')).toBe('bg-[#3b9a4f]');
        expect(deriveAccentColor('medical', 'medium')).toBe('bg-[#f472b6]');
        expect(deriveAccentColor('unknown' as never, 'low')).toBe(
            'bg-gray-500',
        );
    });

    it('derives icon color for all severities and categories', () => {
        expect(deriveIconColor('fire', 'high')).toBe('text-[#e05560]');
        expect(deriveIconColor('fire', 'medium')).toBe('text-[#e07830]');
        expect(deriveIconColor('police', 'medium')).toBe('text-[#6890ff]');
        expect(deriveIconColor('hazard', 'medium')).toBe('text-[#f0b040]');
        expect(deriveIconColor('transit', 'medium')).toBe('text-[#a78bfa]');
        expect(deriveIconColor('go_transit', 'medium')).toBe('text-[#3b9a4f]');
        expect(deriveIconColor('medical', 'medium')).toBe('text-[#f472b6]');
        expect(deriveIconColor('unknown' as never, 'low')).toBe(
            'text-gray-500',
        );
    });
});
