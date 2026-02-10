import { show, update } from '@/routes/notifications';

export type NotificationAlertType =
    | 'all'
    | 'transit'
    | 'emergency'
    | 'accessibility';
export type NotificationSeverityThreshold =
    | 'all'
    | 'minor'
    | 'major'
    | 'critical';

export type NotificationGeofence = {
    name: string | null;
    lat: number;
    lng: number;
    radius_km: number;
};

export type NotificationPreference = {
    alert_type: NotificationAlertType;
    severity_threshold: NotificationSeverityThreshold;
    geofences: NotificationGeofence[];
    subscribed_routes: string[];
    digest_mode: boolean;
    push_enabled: boolean;
};

const ALERT_TYPE_VALUES: NotificationAlertType[] = [
    'all',
    'transit',
    'emergency',
    'accessibility',
];
const SEVERITY_VALUES: NotificationSeverityThreshold[] = [
    'all',
    'minor',
    'major',
    'critical',
];

export const DEFAULT_NOTIFICATION_PREFERENCE: NotificationPreference = {
    alert_type: 'all',
    severity_threshold: 'all',
    geofences: [],
    subscribed_routes: [],
    digest_mode: false,
    push_enabled: true,
};

type NotificationPreferenceResponse = {
    data: unknown;
};

export class NotificationPreferenceServiceError extends Error {
    constructor(
        message: string,
        readonly status: number,
    ) {
        super(message);
    }
}

const isRecord = (value: unknown): value is Record<string, unknown> =>
    typeof value === 'object' && value !== null;

const asString = (value: unknown): string | null =>
    typeof value === 'string' && value.trim().length > 0 ? value.trim() : null;

const asNumber = (value: unknown): number | null =>
    typeof value === 'number' && Number.isFinite(value) ? value : null;

const asBoolean = (value: unknown, fallback: boolean): boolean =>
    typeof value === 'boolean' ? value : fallback;

const normalizeAlertType = (value: unknown): NotificationAlertType =>
    typeof value === 'string' &&
    ALERT_TYPE_VALUES.includes(value as NotificationAlertType)
        ? (value as NotificationAlertType)
        : DEFAULT_NOTIFICATION_PREFERENCE.alert_type;

const normalizeSeverity = (value: unknown): NotificationSeverityThreshold =>
    typeof value === 'string' &&
    SEVERITY_VALUES.includes(value as NotificationSeverityThreshold)
        ? (value as NotificationSeverityThreshold)
        : DEFAULT_NOTIFICATION_PREFERENCE.severity_threshold;

const normalizeGeofences = (value: unknown): NotificationGeofence[] => {
    if (!Array.isArray(value)) {
        return [];
    }

    const geofences: NotificationGeofence[] = [];

    for (const item of value) {
        if (!isRecord(item)) {
            continue;
        }

        const lat = asNumber(item.lat);
        const lng = asNumber(item.lng);
        const radiusKm = asNumber(item.radius_km);

        if (lat === null || lng === null || radiusKm === null) {
            continue;
        }

        geofences.push({
            name: typeof item.name === 'string' ? item.name : null,
            lat,
            lng,
            radius_km: radiusKm,
        });
    }

    return geofences;
};

const normalizeRoutes = (value: unknown): string[] => {
    if (!Array.isArray(value)) {
        return [];
    }

    const routes = value
        .map(asString)
        .filter((routeId): routeId is string => routeId !== null);

    return Array.from(new Set(routes));
};

const normalizePreference = (input: unknown): NotificationPreference => {
    if (!isRecord(input)) {
        return DEFAULT_NOTIFICATION_PREFERENCE;
    }

    return {
        alert_type: normalizeAlertType(input.alert_type),
        severity_threshold: normalizeSeverity(input.severity_threshold),
        geofences: normalizeGeofences(input.geofences),
        subscribed_routes: normalizeRoutes(input.subscribed_routes),
        digest_mode: asBoolean(
            input.digest_mode,
            DEFAULT_NOTIFICATION_PREFERENCE.digest_mode,
        ),
        push_enabled: asBoolean(
            input.push_enabled,
            DEFAULT_NOTIFICATION_PREFERENCE.push_enabled,
        ),
    };
};

const csrfToken = (): string | null => {
    if (typeof document === 'undefined') {
        return null;
    }

    const token = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content');

    return token && token.length > 0 ? token : null;
};

const readErrorMessage = async (response: Response): Promise<string> => {
    try {
        const payload = (await response.json()) as unknown;

        if (isRecord(payload)) {
            const message = payload.message;

            if (typeof message === 'string' && message.length > 0) {
                return message;
            }
        }
    } catch {
        return 'Unable to process request.';
    }

    return `Request failed (${response.status})`;
};

const requestPreference = async (
    input: RequestInfo | URL,
    init: RequestInit,
): Promise<NotificationPreference> => {
    const response = await fetch(input, {
        credentials: 'same-origin',
        ...init,
    });

    if (!response.ok) {
        throw new NotificationPreferenceServiceError(
            await readErrorMessage(response),
            response.status,
        );
    }

    const payload = (await response.json()) as NotificationPreferenceResponse;

    return normalizePreference(payload.data);
};

export const fetchNotificationPreference =
    async (): Promise<NotificationPreference> => {
        return requestPreference(show.url(), {
            method: 'GET',
            headers: {
                Accept: 'application/json',
            },
        });
    };

export const updateNotificationPreference = async (
    preference: NotificationPreference,
): Promise<NotificationPreference> => {
    const token = csrfToken();
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
    };

    if (token) {
        headers['X-CSRF-TOKEN'] = token;
    }

    return requestPreference(update.url(), {
        method: 'PATCH',
        headers,
        body: JSON.stringify(preference),
    });
};
