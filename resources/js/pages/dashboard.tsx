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

function formatTorontoDateTime(value: string | null) {
    if (!value) return '—';

    return new Intl.DateTimeFormat('en-CA', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'America/Toronto',
    }).format(new Date(value));
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
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <div className="grid gap-4 md:grid-cols-3">
                    <Card>
                        <CardHeader>
                            <CardTitle>Active Incidents</CardTitle>
                            <CardDescription>
                                Currently active Toronto Fire incidents
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="text-3xl font-semibold tabular-nums">
                                {active_incidents_count}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Feed Updated</CardTitle>
                            <CardDescription>
                                Latest successful scrape time
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="text-sm text-muted-foreground">
                                {formatTorontoDateTime(latest_feed_updated_at)}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>By Type</CardTitle>
                            <CardDescription>
                                Active incidents grouped by event type
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {active_counts_by_type.length === 0 ? (
                                <div className="text-sm text-muted-foreground">
                                    —
                                </div>
                            ) : (
                                <div className="flex flex-wrap gap-2">
                                    {active_counts_by_type
                                        .slice(0, 8)
                                        .map(({ event_type, count }) => (
                                            <Badge
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
                                                            {incident.alarm_level}
                                                            -alarm
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
                                                Units: {incident.units_dispatched}
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
