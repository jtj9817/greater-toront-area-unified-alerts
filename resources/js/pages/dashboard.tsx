import { Head, usePage } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import type {
    BreadcrumbItem,
    FireIncident,
    FireIncidentTypeCount,
    SharedData,
} from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
];

type DashboardProps = SharedData & {
    active_incidents: FireIncident[];
    active_incidents_count: number;
    active_counts_by_type: FireIncidentTypeCount[];
    latest_feed_updated_at: string | null;
};

const torontoDateTimeFormatter = new Intl.DateTimeFormat('en-CA', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'America/Toronto',
});

function formatTorontoDateTime(value: string | null) {
    if (!value) return '—';

    return torontoDateTimeFormatter.format(new Date(value));
}

function formatLocation(incident: FireIncident) {
    const prime = incident.prime_street?.trim();
    const cross = incident.cross_streets?.trim();

    if (prime && cross) return `${prime} (${cross})`;
    if (prime) return prime;
    if (cross) return cross;

    return 'Location unavailable';
}

export default function Dashboard() {
    const {
        active_incidents,
        active_incidents_count,
        active_counts_by_type,
        latest_feed_updated_at,
    } = usePage<DashboardProps>().props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div
                id="dashboard-container"
                className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
            >
                <div
                    id="dashboard-stats-grid"
                    className="grid gap-4 md:grid-cols-3"
                >
                    <Card id="dashboard-active-incidents-card">
                        <CardHeader id="dashboard-active-incidents-header">
                            <CardTitle id="dashboard-active-incidents-title">
                                Active Incidents
                            </CardTitle>
                            <CardDescription id="dashboard-active-incidents-description">
                                Currently active Toronto Fire incidents
                            </CardDescription>
                        </CardHeader>
                        <CardContent id="dashboard-active-incidents-content">
                            <div
                                id="dashboard-active-incidents-count"
                                className="text-3xl font-semibold tabular-nums"
                            >
                                {active_incidents_count}
                            </div>
                        </CardContent>
                    </Card>

                    <Card id="dashboard-feed-updated-card">
                        <CardHeader id="dashboard-feed-updated-header">
                            <CardTitle id="dashboard-feed-updated-title">
                                Feed Updated
                            </CardTitle>
                            <CardDescription id="dashboard-feed-updated-description">
                                Latest successful scrape time
                            </CardDescription>
                        </CardHeader>
                        <CardContent id="dashboard-feed-updated-content">
                            <div
                                id="dashboard-feed-updated-time"
                                className="text-sm text-muted-foreground"
                            >
                                {formatTorontoDateTime(latest_feed_updated_at)}
                            </div>
                        </CardContent>
                    </Card>

                    <Card id="dashboard-by-type-card">
                        <CardHeader id="dashboard-by-type-header">
                            <CardTitle id="dashboard-by-type-title">
                                By Type
                            </CardTitle>
                            <CardDescription id="dashboard-by-type-description">
                                Active incidents grouped by event type
                            </CardDescription>
                        </CardHeader>
                        <CardContent id="dashboard-by-type-content">
                            {active_counts_by_type.length === 0 ? (
                                <div
                                    id="dashboard-by-type-empty"
                                    className="text-sm text-muted-foreground"
                                >
                                    —
                                </div>
                            ) : (
                                <div
                                    id="dashboard-by-type-badges"
                                    className="flex flex-wrap gap-2"
                                >
                                    {active_counts_by_type
                                        .slice(0, 8)
                                        .map(({ event_type, count }) => (
                                            <Badge
                                                id={`dashboard-by-type-badge-${event_type}`}
                                                key={event_type}
                                                variant="secondary"
                                            >
                                                {event_type}: {count}
                                            </Badge>
                                        ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Active Incident Feed</CardTitle>
                        <CardDescription>
                            Most recent dispatches (active only)
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {active_incidents.length === 0 ? (
                            <div className="text-sm text-muted-foreground">
                                No active incidents found in the database.
                            </div>
                        ) : (
                            <div className="divide-y rounded-md border">
                                {active_incidents.map((incident) => (
                                    <div
                                        key={incident.id}
                                        className="flex flex-col gap-2 p-4"
                                    >
                                        <div className="flex flex-wrap items-center justify-between gap-3">
                                            <div className="min-w-0">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="font-medium">
                                                        {incident.event_type}
                                                    </span>
                                                    <Badge variant="outline">
                                                        {incident.event_num}
                                                    </Badge>
                                                    {incident.alarm_level >
                                                        0 && (
                                                        <Badge variant="destructive">
                                                            {`${incident.alarm_level}-alarm`}
                                                        </Badge>
                                                    )}
                                                </div>
                                                <div className="mt-1 text-sm text-muted-foreground">
                                                    {formatLocation(incident)}
                                                </div>
                                            </div>

                                            <div className="shrink-0 text-sm text-muted-foreground tabular-nums">
                                                {formatTorontoDateTime(
                                                    incident.dispatch_time,
                                                )}
                                            </div>
                                        </div>

                                        {incident.units_dispatched && (
                                            <div className="text-xs text-muted-foreground">
                                                Units:{' '}
                                                {incident.units_dispatched}
                                            </div>
                                        )}
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
