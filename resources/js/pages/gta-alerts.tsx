import { Head } from '@inertiajs/react';
import React from 'react';
import AlertsApp from '../features/gta-alerts/App';
import type { UnifiedAlertResource } from '../features/gta-alerts/types';

interface GTAAlertsProps {
  alerts: {
    data: UnifiedAlertResource[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
  };
  filters: {
    status: 'all' | 'active' | 'cleared';
  };
  latest_feed_updated_at: string | null;
}

export default function GTAAlerts({ alerts, filters, latest_feed_updated_at }: GTAAlertsProps) {
  return (
    <>
      <Head title="GTA Alerts" />
      <AlertsApp alerts={alerts} filters={filters} latestFeedUpdatedAt={latest_feed_updated_at} />
    </>
  );
}
