import { Link } from '@inertiajs/react';
import React, { useEffect, useMemo, useState } from 'react';
import { login, register } from '@/routes';
import {
    DEFAULT_NOTIFICATION_PREFERENCE,
    fetchNotificationPreference,
    NotificationPreferenceServiceError,
    type NotificationAlertType,
    type NotificationGeofence,
    type NotificationPreference,
    type NotificationSeverityThreshold,
    updateNotificationPreference,
} from '../services/NotificationPreferenceService';
import { Icon } from './Icon';

const ALERT_TYPE_OPTIONS: Array<{ value: NotificationAlertType; label: string }> =
    [
        { value: 'all', label: 'All alerts' },
        { value: 'transit', label: 'Transit only' },
        { value: 'emergency', label: 'Emergency only' },
        { value: 'accessibility', label: 'Accessibility alerts' },
    ];

const SEVERITY_OPTIONS: Array<{
    value: NotificationSeverityThreshold;
    label: string;
}> = [
    { value: 'all', label: 'All severities' },
    { value: 'minor', label: 'Minor and above' },
    { value: 'major', label: 'Major and above' },
    { value: 'critical', label: 'Critical only' },
];

const ZONE_PRESETS = [
    { id: 'downtown', label: 'Downtown Core', lat: 43.6535, lng: -79.3839 },
    { id: 'north-york', label: 'North York Centre', lat: 43.7615, lng: -79.4111 },
    { id: 'scarborough', label: 'Scarborough Town Centre', lat: 43.7756, lng: -79.2576 },
    { id: 'etobicoke', label: 'Etobicoke Centre', lat: 43.6205, lng: -79.5132 },
    { id: 'mississauga', label: 'Mississauga City Centre', lat: 43.589, lng: -79.6441 },
    { id: 'markham', label: 'Markham Centre', lat: 43.8567, lng: -79.337 },
];

const RADIUS_PRESETS = [1, 2, 5, 10];
const FALLBACK_ROUTES = ['1', '2', '4', '501', '504', 'GO-KI', 'GO-LW'];

const formatCoordinates = (geofence: NotificationGeofence): string =>
    `${geofence.lat.toFixed(4)}, ${geofence.lng.toFixed(4)}`;

type SettingsViewProps = {
    authUserId: number | null;
    availableRoutes: string[];
};

export const SettingsView: React.FC<SettingsViewProps> = ({
    authUserId,
    availableRoutes,
}) => {
    const [preference, setPreference] = useState<NotificationPreference>(
        DEFAULT_NOTIFICATION_PREFERENCE,
    );
    const [isLoading, setIsLoading] = useState<boolean>(authUserId !== null);
    const [isSaving, setIsSaving] = useState(false);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [successMessage, setSuccessMessage] = useState<string | null>(null);
    const [selectedZoneId, setSelectedZoneId] = useState<string>(
        ZONE_PRESETS[0]?.id ?? '',
    );
    const [selectedRadiusKm, setSelectedRadiusKm] = useState<number>(
        RADIUS_PRESETS[1] ?? 2,
    );

    useEffect(() => {
        if (authUserId === null) {
            setIsLoading(false);
            return;
        }

        let isMounted = true;

        const loadPreference = async (): Promise<void> => {
            setIsLoading(true);
            setErrorMessage(null);

            try {
                const result = await fetchNotificationPreference();

                if (isMounted) {
                    setPreference(result);
                }
            } catch (error) {
                if (!isMounted) {
                    return;
                }

                if (
                    error instanceof NotificationPreferenceServiceError &&
                    error.status === 401
                ) {
                    setErrorMessage('Please sign in again to manage settings.');
                } else {
                    setErrorMessage(
                        'Unable to load notification preferences right now.',
                    );
                }
            } finally {
                if (isMounted) {
                    setIsLoading(false);
                }
            }
        };

        void loadPreference();

        return () => {
            isMounted = false;
        };
    }, [authUserId]);

    const routeOptions = useMemo(() => {
        const mergedRoutes = [
            ...availableRoutes,
            ...preference.subscribed_routes,
            ...FALLBACK_ROUTES,
        ]
            .map((route) => route.trim())
            .filter((route) => route.length > 0);

        return Array.from(new Set(mergedRoutes)).sort((left, right) =>
            left.localeCompare(right, undefined, { numeric: true }),
        );
    }, [availableRoutes, preference.subscribed_routes]);

    const routesDisabled =
        preference.alert_type === 'emergency' ||
        preference.alert_type === 'accessibility';

    const updatePreference = <K extends keyof NotificationPreference>(
        key: K,
        value: NotificationPreference[K],
    ): void => {
        setPreference((current) => ({
            ...current,
            [key]: value,
        }));
        setSuccessMessage(null);
    };

    const toggleRoute = (routeId: string): void => {
        setPreference((current) => {
            const alreadySelected = current.subscribed_routes.includes(routeId);

            return {
                ...current,
                subscribed_routes: alreadySelected
                    ? current.subscribed_routes.filter((route) => route !== routeId)
                    : [...current.subscribed_routes, routeId],
            };
        });
        setSuccessMessage(null);
    };

    const addGeofence = (): void => {
        const selectedZone = ZONE_PRESETS.find((zone) => zone.id === selectedZoneId);

        if (!selectedZone) {
            return;
        }

        const geofenceToAdd: NotificationGeofence = {
            name: selectedZone.label,
            lat: selectedZone.lat,
            lng: selectedZone.lng,
            radius_km: selectedRadiusKm,
        };

        const duplicateExists = preference.geofences.some(
            (zone) =>
                zone.name === geofenceToAdd.name &&
                zone.radius_km === geofenceToAdd.radius_km,
        );

        if (duplicateExists) {
            setErrorMessage('That zone and radius combination is already added.');
            return;
        }

        setErrorMessage(null);
        setPreference((current) => ({
            ...current,
            geofences: [...current.geofences, geofenceToAdd],
        }));
        setSuccessMessage(null);
    };

    const removeGeofence = (indexToRemove: number): void => {
        setPreference((current) => ({
            ...current,
            geofences: current.geofences.filter(
                (_, index) => index !== indexToRemove,
            ),
        }));
        setSuccessMessage(null);
    };

    const savePreferences = async (): Promise<void> => {
        setIsSaving(true);
        setErrorMessage(null);
        setSuccessMessage(null);

        try {
            const savedPreference = await updateNotificationPreference(preference);
            setPreference(savedPreference);
            setSuccessMessage('Notification settings saved.');
        } catch (error) {
            if (
                error instanceof NotificationPreferenceServiceError &&
                error.status === 422
            ) {
                setErrorMessage(
                    'One or more settings are invalid. Please review your selections.',
                );
            } else {
                setErrorMessage(
                    'Unable to save changes right now. Please try again shortly.',
                );
            }
        } finally {
            setIsSaving(false);
        }
    };

    if (authUserId === null) {
        return (
            <div className="mx-auto w-full max-w-4xl p-4 md:p-6">
                <div className="rounded-2xl border border-white/10 bg-surface-dark p-6 md:p-8">
                    <h2 className="mb-3 flex items-center gap-3 text-2xl font-bold text-white">
                        <Icon name="lock" className="text-primary" />
                        Sign in to configure notification preferences
                    </h2>
                    <p className="mb-6 max-w-2xl text-sm text-text-secondary">
                        The live feed is public, but notification settings and
                        real-time personal alerts are available for signed-in users
                        only.
                    </p>
                    <div className="flex flex-wrap gap-3">
                        <Link
                            href={login().url}
                            className="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white transition hover:bg-primary/90"
                        >
                            <Icon name="login" />
                            Sign in
                        </Link>
                        <Link
                            href={register().url}
                            className="inline-flex items-center gap-2 rounded-lg border border-white/15 bg-white/5 px-4 py-2 text-sm font-medium text-white transition hover:bg-white/10"
                        >
                            <Icon name="person_add" />
                            Create account
                        </Link>
                    </div>
                </div>
            </div>
        );
    }

    if (isLoading) {
        return (
            <div className="mx-auto w-full max-w-4xl p-4 md:p-6">
                <div className="rounded-2xl border border-white/10 bg-surface-dark p-6 text-sm text-text-secondary">
                    Loading notification preferences...
                </div>
            </div>
        );
    }

    return (
        <div className="mx-auto w-full max-w-5xl p-4 md:p-6">
            <div className="mb-6 flex flex-col gap-2 border-b border-white/10 pb-5">
                <h2 className="flex items-center gap-3 text-2xl font-bold text-white">
                    <Icon name="notifications_active" className="text-primary" />
                    Notification Settings
                </h2>
                <p className="text-sm text-text-secondary">
                    Configure what alerts you receive and how they are delivered in
                    the app.
                </p>
            </div>

            {errorMessage && (
                <div className="mb-5 rounded-lg border border-coral/40 bg-coral/10 px-4 py-3 text-sm text-coral">
                    {errorMessage}
                </div>
            )}

            {successMessage && (
                <div className="mb-5 rounded-lg border border-forest/40 bg-forest/10 px-4 py-3 text-sm text-forest">
                    {successMessage}
                </div>
            )}

            <div className="space-y-5">
                <section className="rounded-xl border border-white/10 bg-surface-dark p-4 md:p-5">
                    <h3 className="mb-4 text-sm font-semibold tracking-wide text-primary uppercase">
                        Source Filters
                    </h3>
                    <label className="mb-2 block text-xs text-text-secondary">
                        Alert source
                    </label>
                    <select
                        value={preference.alert_type}
                        onChange={(event) =>
                            updatePreference(
                                'alert_type',
                                event.target.value as NotificationAlertType,
                            )
                        }
                        className="w-full rounded-lg border border-white/15 bg-background-dark px-3 py-2 text-sm text-white focus:border-primary/60 focus:outline-none"
                    >
                        {ALERT_TYPE_OPTIONS.map((option) => (
                            <option
                                key={option.value}
                                value={option.value}
                                className="bg-background-dark"
                            >
                                {option.label}
                            </option>
                        ))}
                    </select>
                </section>

                <section className="rounded-xl border border-white/10 bg-surface-dark p-4 md:p-5">
                    <h3 className="mb-4 text-sm font-semibold tracking-wide text-primary uppercase">
                        Severity Filter
                    </h3>
                    <label
                        htmlFor="severity-threshold"
                        className="mb-2 block text-xs text-text-secondary"
                    >
                        Severity threshold
                    </label>
                    <select
                        id="severity-threshold"
                        value={preference.severity_threshold}
                        onChange={(event) =>
                            updatePreference(
                                'severity_threshold',
                                event.target.value as NotificationSeverityThreshold,
                            )
                        }
                        className="w-full rounded-lg border border-white/15 bg-background-dark px-3 py-2 text-sm text-white focus:border-primary/60 focus:outline-none"
                    >
                        {SEVERITY_OPTIONS.map((option) => (
                            <option
                                key={option.value}
                                value={option.value}
                                className="bg-background-dark"
                            >
                                {option.label}
                            </option>
                        ))}
                    </select>
                </section>

                <section className="rounded-xl border border-white/10 bg-surface-dark p-4 md:p-5">
                    <div className="mb-4 flex items-center justify-between">
                        <h3 className="text-sm font-semibold tracking-wide text-primary uppercase">
                            Route Filters
                        </h3>
                        {routesDisabled && (
                            <span className="text-xs text-text-secondary">
                                Disabled when source is non-transit
                            </span>
                        )}
                    </div>
                    <div className="grid gap-2 sm:grid-cols-2 md:grid-cols-3">
                        {routeOptions.map((routeId) => (
                            <label
                                key={routeId}
                                className={`flex items-center gap-2 rounded-lg border px-3 py-2 text-sm transition ${
                                    routesDisabled
                                        ? 'border-white/10 bg-white/5 text-text-secondary'
                                        : 'border-white/15 bg-background-dark/60 text-white hover:border-primary/40'
                                }`}
                            >
                                <input
                                    type="checkbox"
                                    aria-label={`Route ${routeId}`}
                                    checked={preference.subscribed_routes.includes(
                                        routeId,
                                    )}
                                    disabled={routesDisabled}
                                    onChange={() => toggleRoute(routeId)}
                                    className="h-4 w-4 accent-primary"
                                />
                                {routeId}
                            </label>
                        ))}
                    </div>
                </section>

                <section className="rounded-xl border border-white/10 bg-surface-dark p-4 md:p-5">
                    <h3 className="mb-4 text-sm font-semibold tracking-wide text-primary uppercase">
                        Geofence Zones
                    </h3>
                    <div className="mb-4 grid gap-3 md:grid-cols-[1.3fr_0.7fr_auto]">
                        <select
                            value={selectedZoneId}
                            onChange={(event) =>
                                setSelectedZoneId(event.target.value)
                            }
                            className="rounded-lg border border-white/15 bg-background-dark px-3 py-2 text-sm text-white focus:border-primary/60 focus:outline-none"
                        >
                            {ZONE_PRESETS.map((zone) => (
                                <option
                                    key={zone.id}
                                    value={zone.id}
                                    className="bg-background-dark"
                                >
                                    {zone.label}
                                </option>
                            ))}
                        </select>
                        <select
                            value={selectedRadiusKm}
                            onChange={(event) =>
                                setSelectedRadiusKm(Number(event.target.value))
                            }
                            className="rounded-lg border border-white/15 bg-background-dark px-3 py-2 text-sm text-white focus:border-primary/60 focus:outline-none"
                        >
                            {RADIUS_PRESETS.map((radiusKm) => (
                                <option
                                    key={radiusKm}
                                    value={radiusKm}
                                    className="bg-background-dark"
                                >
                                    {radiusKm} km radius
                                </option>
                            ))}
                        </select>
                        <button
                            type="button"
                            onClick={addGeofence}
                            className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white transition hover:bg-primary/90"
                        >
                            Add zone
                        </button>
                    </div>

                    {preference.geofences.length === 0 ? (
                        <p className="text-sm text-text-secondary">
                            No zones configured yet.
                        </p>
                    ) : (
                        <div className="space-y-2">
                            {preference.geofences.map((zone, index) => (
                                <div
                                    key={`${zone.name ?? 'zone'}-${zone.lat}-${zone.lng}-${zone.radius_km}-${index}`}
                                    className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-white/15 bg-background-dark/60 px-3 py-2"
                                >
                                    <div className="min-w-0">
                                        <div className="text-sm font-medium text-white">
                                            {zone.name ?? 'Custom zone'} (
                                            {zone.radius_km} km)
                                        </div>
                                        <div className="text-xs text-text-secondary">
                                            {formatCoordinates(zone)}
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => removeGeofence(index)}
                                        className="rounded-md border border-white/15 px-2 py-1 text-xs text-white transition hover:border-coral/50 hover:text-coral"
                                    >
                                        Remove
                                    </button>
                                </div>
                            ))}
                        </div>
                    )}
                </section>

                <section className="rounded-xl border border-white/10 bg-surface-dark p-4 md:p-5">
                    <h3 className="mb-4 text-sm font-semibold tracking-wide text-primary uppercase">
                        Delivery
                    </h3>
                    <div className="space-y-3">
                        <label className="flex items-center justify-between gap-3 rounded-lg border border-white/15 bg-background-dark/60 px-3 py-2 text-sm text-white">
                            Enable real-time push toasts
                            <input
                                type="checkbox"
                                checked={preference.push_enabled}
                                onChange={(event) =>
                                    updatePreference(
                                        'push_enabled',
                                        event.target.checked,
                                    )
                                }
                                className="h-4 w-4 accent-primary"
                            />
                        </label>
                        <label className="flex items-center justify-between gap-3 rounded-lg border border-white/15 bg-background-dark/60 px-3 py-2 text-sm text-white">
                            Enable daily digest mode
                            <input
                                type="checkbox"
                                checked={preference.digest_mode}
                                onChange={(event) =>
                                    updatePreference(
                                        'digest_mode',
                                        event.target.checked,
                                    )
                                }
                                className="h-4 w-4 accent-primary"
                            />
                        </label>
                    </div>
                </section>
            </div>

            <div className="mt-6 flex justify-end">
                <button
                    type="button"
                    onClick={() => {
                        void savePreferences();
                    }}
                    disabled={isSaving}
                    className="rounded-lg bg-primary px-5 py-2 text-sm font-medium text-white transition hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    {isSaving ? 'Saving...' : 'Save notification settings'}
                </button>
            </div>
        </div>
    );
};
