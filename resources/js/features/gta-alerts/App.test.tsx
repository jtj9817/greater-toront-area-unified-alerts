import { render, screen } from '@testing-library/react';
import React from 'react';
import { describe, it, expect, vi } from 'vitest';

import AlertsApp from './App';
import type { UnifiedAlertResource } from './domain/alerts';

function buildBaseProps(alerts: UnifiedAlertResource[]) {
    return {
        alerts: {
            data: alerts,
            links: { prev: null, next: null },
            meta: { current_page: 1, last_page: 1, total: alerts.length },
        },
        filters: { status: 'all' as const },
        latestFeedUpdatedAt: null,
    };
}

function domainWarnMessages(warnSpy: ReturnType<typeof vi.spyOn>): string[] {
    return warnSpy.mock.calls
        .map((args: unknown[]) => args[0])
        .filter(
            (firstArg: unknown): firstArg is string =>
                typeof firstArg === 'string' &&
                firstArg.startsWith('[DomainAlert]'),
        );
}

function fireResource(overrides: Partial<UnifiedAlertResource> = {}) {
    const timestamp = new Date('2026-02-03T12:00:00Z').toISOString();
    const base: UnifiedAlertResource = {
        id: 'fire:E1',
        source: 'fire',
        external_id: 'E1',
        is_active: true,
        timestamp,
        title: 'STRUCTURE FIRE',
        location: { name: 'MAIN ST / CROSS RD', lat: null, lng: null },
        meta: {
            alarm_level: 2,
            event_num: 'E1',
            units_dispatched: null,
            beat: null,
        },
    };
    return { ...base, ...overrides };
}

function policeResource(overrides: Partial<UnifiedAlertResource> = {}) {
    const timestamp = new Date('2026-02-03T12:01:00Z').toISOString();
    const base: UnifiedAlertResource = {
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
    return { ...base, ...overrides };
}

function ttcTransitResource(overrides: Partial<UnifiedAlertResource> = {}) {
    const timestamp = new Date('2026-02-03T12:02:00Z').toISOString();
    const base: UnifiedAlertResource = {
        id: 'transit:api:61748',
        source: 'transit',
        external_id: 'api:61748',
        is_active: true,
        timestamp,
        title: 'Line 1 delay',
        location: { name: 'St Clair Station', lat: 43.7, lng: -79.4 },
        meta: {
            route_type: 'Subway',
            route: '1',
            severity: 'Critical',
            effect: 'REDUCED_SERVICE',
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
    return { ...base, ...overrides };
}

function goTransitResource(overrides: Partial<UnifiedAlertResource> = {}) {
    const timestamp = new Date('2026-02-03T12:03:00Z').toISOString();
    const base: UnifiedAlertResource = {
        id: 'go_transit:12345',
        source: 'go_transit',
        external_id: '12345',
        is_active: true,
        timestamp,
        title: 'Lakeshore East delay',
        location: { name: 'Union Station', lat: 43.7, lng: -79.4 },
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
    return { ...base, ...overrides };
}

describe('GTA Alerts App (typed domain enforcement boundary)', () => {
    it('renders valid alerts and discards invalid meta (warns instead of crashing)', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const valid = fireResource({ title: 'VALID FIRE' });
        const invalid = fireResource({
            id: 'fire:INVALID',
            external_id: 'INVALID',
            title: 'INVALID FIRE',
            meta: {
                alarm_level: '2',
                event_num: 'INVALID',
                units_dispatched: null,
                beat: null,
            },
        }) as unknown as UnifiedAlertResource;

        expect(() =>
            render(<AlertsApp {...buildBaseProps([valid, invalid])} />),
        ).not.toThrow();

        expect(screen.getByText('VALID FIRE')).toBeInTheDocument();
        expect(screen.queryByText('INVALID FIRE')).not.toBeInTheDocument();

        const messages = domainWarnMessages(warn);
        expect(messages.length).toBeGreaterThanOrEqual(1);
        expect(
            messages.some((msg) => msg.includes('Invalid fire alert')),
        ).toBe(true);

        warn.mockRestore();
    });

    it('discards invalid envelope resources but keeps valid ones', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const valid = policeResource({ title: 'VALID POLICE' });
        const invalidEnvelope = policeResource({
            id: 'police:BAD_ENV',
            external_id: 'BAD_ENV',
            title: 'BAD ENVELOPE',
            location: { name: 'X', lat: 'not-a-number', lng: null },
        } as unknown as Partial<UnifiedAlertResource>) as unknown as UnifiedAlertResource;

        expect(() =>
            render(
                <AlertsApp {...buildBaseProps([valid, invalidEnvelope])} />,
            ),
        ).not.toThrow();

        expect(screen.getByText('VALID POLICE')).toBeInTheDocument();
        expect(screen.queryByText('BAD ENVELOPE')).not.toBeInTheDocument();

        const messages = domainWarnMessages(warn);
        expect(messages.length).toBeGreaterThanOrEqual(1);
        expect(
            messages.some((msg) => msg.includes('Invalid resource envelope')),
        ).toBe(true);

        warn.mockRestore();
    });

    it('handles a fully invalid alert list by rendering empty state (no crash)', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const invalid1 = fireResource({
            id: 'fire:BAD1',
            external_id: 'BAD1',
            title: 'BAD1',
            meta: {
                alarm_level: 'oops',
                event_num: 'BAD1',
                units_dispatched: null,
                beat: null,
            },
        }) as unknown as UnifiedAlertResource;

        const invalid2 = goTransitResource({
            id: 'go_transit:BAD2',
            external_id: 'BAD2',
            title: 'BAD2',
            meta: {
                alert_type: 'saag',
                direction: 'Eastbound',
                service_mode: 'Train',
                sub_category: 'TDELAY',
                corridor_code: 'LE',
                trip_number: null,
                delay_duration: 123,
                line_colour: null,
                message_body: null,
            },
        }) as unknown as UnifiedAlertResource;

        expect(() =>
            render(<AlertsApp {...buildBaseProps([invalid1, invalid2])} />),
        ).not.toThrow();

        expect(
            screen.getByText('No alerts match your filters'),
        ).toBeInTheDocument();

        const messages = domainWarnMessages(warn);
        expect(messages.length).toBeGreaterThanOrEqual(1);

        warn.mockRestore();
    });

    it('renders multiple valid sources without DomainAlert warnings', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        render(
            <AlertsApp
                {...buildBaseProps([
                    fireResource({ title: 'FIRE OK' }),
                    policeResource({ title: 'POLICE OK' }),
                    ttcTransitResource({ title: 'TTC OK' }),
                    goTransitResource({ title: 'GO OK' }),
                ])}
            />,
        );

        expect(screen.getByText('FIRE OK')).toBeInTheDocument();
        expect(screen.getByText('POLICE OK')).toBeInTheDocument();
        expect(screen.getByText('TTC OK')).toBeInTheDocument();
        expect(screen.getByText('GO OK')).toBeInTheDocument();

        expect(domainWarnMessages(warn)).toEqual([]);

        warn.mockRestore();
    });
});
