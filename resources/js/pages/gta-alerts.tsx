import { Head } from '@inertiajs/react';
import React from 'react';
import AlertsApp from '../features/gta-alerts/App';
import type { IncidentResource } from '../features/gta-alerts/types';

interface GTAAlertsProps {
  incidents: {
    data: IncidentResource[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
  };
  filters: {
    search: string;
  };
  latest_feed_updated_at: string | null;
}

export default function GTAAlerts({ incidents, filters, latest_feed_updated_at }: GTAAlertsProps) {
  return (
    <>
      <Head title="GTA Alerts" />
      <AlertsApp incidents={incidents} filters={filters} latestFeedUpdatedAt={latest_feed_updated_at} />
    </>
  );
}
