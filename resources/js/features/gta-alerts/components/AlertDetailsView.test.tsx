import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { UnifiedAlertResource } from '../domain/alerts';
import { AlertService } from '../services/AlertService';
import { AlertDetailsView } from './AlertDetailsView';

function toDomainAlert(resource: UnifiedAlertResource) {
    const alert = AlertService.mapUnifiedAlertToDomainAlert(resource);
    if (!alert) {
        throw new Error(`Expected DomainAlert for ${resource.id}`);
    }

    return alert;
}

describe('AlertDetailsView', () => {
    const timestamp = new Date('2026-02-03T12:00:00Z').toISOString();

    it('renders fire detail branch and supports back navigation', () => {
        const onBack = vi.fn();
        const fireAlert = toDomainAlert({
            id: 'fire:E1',
            source: 'fire',
            external_id: 'E1',
            is_active: true,
            timestamp,
            title: 'STRUCTURE FIRE',
            location: { name: 'Main St', lat: null, lng: null },
            meta: {
                alarm_level: 2,
                units_dispatched: 'P1',
                beat: 'B1',
                event_num: 'E1',
            },
        });

        render(<AlertDetailsView alert={fireAlert} onBack={onBack} />);

        expect(screen.getByText('High Severity Response')).toBeInTheDocument();
        expect(screen.getByText('Response Tier')).toBeInTheDocument();

        fireEvent.click(screen.getAllByRole('button')[0]);
        expect(onBack).toHaveBeenCalledTimes(1);
    });

    it('renders police detail branch for police kind', () => {
        const policeAlert = toDomainAlert({
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
        });

        render(<AlertDetailsView alert={policeAlert} onBack={() => {}} />);

        expect(screen.getByText('Tactical Operation')).toBeInTheDocument();
        expect(screen.getByText('Public Safety Advisory')).toBeInTheDocument();
    });

    it('renders TTC transit detail branch for transit kind', () => {
        const transitAlert = toDomainAlert({
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
        });

        render(<AlertDetailsView alert={transitAlert} onBack={() => {}} />);

        expect(screen.getByText('Service Notice')).toBeInTheDocument();
        expect(screen.getByText('TTC Control')).toBeInTheDocument();
    });

    it('renders GO Transit detail branch for go_transit kind', () => {
        const goAlert = toDomainAlert({
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
        });

        render(<AlertDetailsView alert={goAlert} onBack={() => {}} />);

        expect(screen.getByText('GO Service Notice')).toBeInTheDocument();
        expect(screen.getByText('Operations Note')).toBeInTheDocument();
    });
});
