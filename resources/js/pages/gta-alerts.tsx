import { Head, usePage } from '@inertiajs/react';
import React from 'react';
import AlertsApp from '../features/gta-alerts/App';
import type { UnifiedAlertResource } from '../features/gta-alerts/domain/alerts';

interface GTAAlertsProps {
    alerts: {
        data: UnifiedAlertResource[];
        next_cursor: string | null;
    };
    filters: {
        status: 'all' | 'active' | 'cleared';
        source?: string | null;
        q?: string | null;
        since?: string | null;
    };
    latest_feed_updated_at: string | null;
    subscription_route_options: string[];
}

type GTAAlertsSharedProps = {
    auth?: {
        user?: {
            id?: number;
        } | null;
    };
};

export default function GTAAlerts({
    alerts,
    filters,
    latest_feed_updated_at,
    subscription_route_options,
}: GTAAlertsProps) {
    const page = usePage<GTAAlertsSharedProps>();
    const rawAuthUserId = page.props.auth?.user?.id;
    const authUserId = typeof rawAuthUserId === 'number' ? rawAuthUserId : null;

    return (
        <div id="gta-alerts-page">
            <Head title="GTA Alerts" />
            <AlertsApp
                alerts={alerts}
                filters={filters}
                latestFeedUpdatedAt={latest_feed_updated_at}
                authUserId={authUserId}
                subscriptionRouteOptions={subscription_route_options}
            />
        </div>
    );
}
