import React from 'react';
import { Head } from '@inertiajs/react';
import AlertsApp from '../features/gta-alerts/App';

export default function GTAAlerts() {
  return (
    <>
      <Head title="GTA Alerts" />
      <AlertsApp />
    </>
  );
}
