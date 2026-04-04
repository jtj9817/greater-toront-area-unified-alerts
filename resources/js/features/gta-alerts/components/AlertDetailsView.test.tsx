import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { UnifiedAlertResource } from '../domain/alerts';
import { AlertService } from '../services/AlertService';
import { AlertDetailsView } from './AlertDetailsView';

vi.mock('./SceneIntelTimeline', () => ({
    SceneIntelTimeline: () => <div data-testid="scene-intel-timeline" />,
}));

vi.mock('./AlertLocationMap', () => ({
    AlertLocationMap: ({
        idBase,
        locationName,
    }: {
        idBase: string;
        locationName: string;
    }) => (
        <div id={`${idBase}-map-wrapper`} data-testid="alert-location-map">
            {locationName}
        </div>
    ),
    AlertLocationUnavailable: ({
        idBase,
        locationName,
    }: {
        idBase: string;
        locationName: string;
    }) => (
        <div
            id={`${idBase}-location-unavailable`}
            data-testid="alert-location-unavailable"
        >
            {locationName}
        </div>
    ),
}));

function toDomainAlert(resource: UnifiedAlertResource) {
    const alert = AlertService.mapUnifiedAlertToDomainAlert(resource);
    if (!alert) {
        throw new Error(`Expected DomainAlert for ${resource.id}`);
    }

    return alert;
}

describe('AlertDetailsView', () => {
    const timestamp = new Date('2026-02-03T12:00:00Z').toISOString();

    const defaultProps = {
        isSaved: false,
        isPending: false,
        onToggleSave: vi.fn(),
        onShare: vi.fn(),
    };

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

        render(
            <AlertDetailsView
                alert={fireAlert}
                onBack={onBack}
                {...defaultProps}
            />,
        );

        expect(screen.getByText('High Severity Response')).toBeInTheDocument();
        expect(screen.getByText('Response Tier')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /arrow_back/i }));
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

        render(
            <AlertDetailsView
                alert={policeAlert}
                onBack={() => {}}
                {...defaultProps}
            />,
        );

        expect(screen.getByText('Tactical Operation')).toBeInTheDocument();
        expect(screen.getByText('Public Safety Advisory')).toBeInTheDocument();
        expect(screen.getByTestId('alert-location-map')).toBeInTheDocument();
        expect(
            screen.queryByTestId('alert-location-unavailable'),
        ).not.toBeInTheDocument();
        expect(screen.queryByText('Interactive Map Loading...')).toBeNull();

        const idBase = 'gta-alerts-alert-details-police:123';
        expect(
            document.getElementById(`${idBase}-location-section`),
        ).not.toBeNull();
        expect(document.getElementById(`${idBase}-map-wrapper`)).not.toBeNull();
    });

    it('renders unavailable location map state for fire alerts without renderable coordinates', () => {
        const fireAlert = toDomainAlert({
            id: 'fire:E2',
            source: 'fire',
            external_id: 'E2',
            is_active: true,
            timestamp,
            title: 'FIRE INVESTIGATION',
            location: { name: 'Queen St W', lat: null, lng: null },
            meta: {
                alarm_level: 1,
                units_dispatched: 'P2',
                beat: 'B2',
                event_num: 'E2',
            },
        });

        render(
            <AlertDetailsView
                alert={fireAlert}
                onBack={() => {}}
                {...defaultProps}
            />,
        );

        expect(
            screen.getByTestId('alert-location-unavailable'),
        ).toBeInTheDocument();
        expect(
            screen.queryByTestId('alert-location-map'),
        ).not.toBeInTheDocument();
        expect(screen.queryByText('Interactive Map Loading...')).toBeNull();

        const idBase = 'gta-alerts-alert-details-fire:E2';
        expect(
            document.getElementById(`${idBase}-location-section`),
        ).not.toBeNull();
        expect(
            document.getElementById(`${idBase}-location-unavailable`),
        ).not.toBeNull();
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

        render(
            <AlertDetailsView
                alert={transitAlert}
                onBack={() => {}}
                {...defaultProps}
            />,
        );

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

        render(
            <AlertDetailsView
                alert={goAlert}
                onBack={() => {}}
                {...defaultProps}
            />,
        );

        expect(screen.getByText('GO Service Notice')).toBeInTheDocument();
        expect(screen.getByText('Operations Note')).toBeInTheDocument();
    });

    it('renders YRT detail branch for yrt kind', () => {
        const yrtAlert = toDomainAlert({
            id: 'yrt:a1234',
            source: 'yrt',
            external_id: 'a1234',
            is_active: true,
            timestamp,
            title: '52 - Holland Landing detour',
            location: { name: 'Route 52', lat: null, lng: null },
            meta: {
                details_url: 'https://www.yrt.ca/en/news/52-detour.aspx',
                description_excerpt: 'Detour in effect near Green Lane.',
                body_text: 'Routes affected: 52 and 58.',
                posted_at: '2026-04-01 10:20:00',
                feed_updated_at: '2026-04-01 10:25:00',
            },
        });

        render(
            <AlertDetailsView
                alert={yrtAlert}
                onBack={() => {}}
                {...defaultProps}
            />,
        );

        expect(screen.getByText('YRT Service Advisory')).toBeInTheDocument();
        expect(screen.getByText('YRT Notice')).toBeInTheDocument();
    });

    it('renders DRT detail branch for drt kind', () => {
        const drtAlert = toDomainAlert({
            id: 'drt:conlin-grandview-detour',
            source: 'drt',
            external_id: 'conlin-grandview-detour',
            is_active: true,
            timestamp,
            title: 'Conlin Grandview Detour',
            location: { name: '900, 920', lat: null, lng: null },
            meta: {
                details_url:
                    'https://www.durhamregiontransit.com/en/news/conlin-grandview-detour.aspx',
                when_text: 'Effective until further notice',
                route_text: '900, 920',
                body_text:
                    'Routes 900 and 920 are detoured via Grandview Drive.',
                posted_at: '2026-04-03 10:20:00',
                feed_updated_at: '2026-04-03 10:25:00',
            },
        });

        render(
            <AlertDetailsView
                alert={drtAlert}
                onBack={() => {}}
                {...defaultProps}
            />,
        );

        expect(screen.getByText('DRT Service Advisory')).toBeInTheDocument();
        expect(screen.getByText('DRT Notice')).toBeInTheDocument();
        expect(screen.getByText('DRT')).toBeInTheDocument();
    });

    it('handles save toggle and displays saved state', () => {
        const fireAlert = toDomainAlert({
            id: 'fire:E1',
            source: 'fire',
            external_id: 'E1',
            is_active: true,
            timestamp,
            title: 'STRUCTURE FIRE',
            location: { name: 'Main St', lat: null, lng: null },
            meta: {
                event_num: 'E1',
                alarm_level: 1,
                units_dispatched: 'P1',
                beat: 'B1',
            },
        });

        const { rerender } = render(
            <AlertDetailsView
                alert={fireAlert}
                onBack={() => {}}
                {...defaultProps}
            />,
        );

        const saveBtn = screen.getByRole('button', { name: /Save Alert/i });
        fireEvent.click(saveBtn);
        expect(defaultProps.onToggleSave).toHaveBeenCalledTimes(1);

        rerender(
            <AlertDetailsView
                alert={fireAlert}
                onBack={() => {}}
                {...defaultProps}
                isSaved={true}
            />,
        );

        expect(screen.getByText('Saved')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Saved/i })).toHaveClass(
            'bg-primary',
        );
    });

    it('shows loading state when isPending is true', () => {
        const fireAlert = toDomainAlert({
            id: 'fire:E1',
            source: 'fire',
            external_id: 'E1',
            is_active: true,
            timestamp,
            title: 'STRUCTURE FIRE',
            location: { name: 'Main St', lat: null, lng: null },
            meta: {
                event_num: 'E1',
                alarm_level: 1,
                units_dispatched: 'P1',
                beat: 'B1',
            },
        });

        render(
            <AlertDetailsView
                alert={fireAlert}
                onBack={() => {}}
                {...defaultProps}
                isPending={true}
            />,
        );

        const saveBtnActual = screen.getAllByRole('button')[2];

        expect(saveBtnActual).toBeDisabled();
        expect(
            saveBtnActual.querySelector('.animate-spin'),
        ).toBeInTheDocument();
    });

    it('calls onShare when Share Alert is clicked', () => {
        const fireAlert = toDomainAlert({
            id: 'fire:E1',
            source: 'fire',
            external_id: 'E1',
            is_active: true,
            timestamp,
            title: 'STRUCTURE FIRE',
            location: { name: 'Main St', lat: null, lng: null },
            meta: {
                event_num: 'E1',
                alarm_level: 1,
                units_dispatched: 'P1',
                beat: 'B1',
            },
        });

        const onShare = vi.fn();

        render(
            <AlertDetailsView
                alert={fireAlert}
                onBack={() => {}}
                {...defaultProps}
                onShare={onShare}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: /Share Alert/i }));

        expect(onShare).toHaveBeenCalledTimes(1);
    });
});
