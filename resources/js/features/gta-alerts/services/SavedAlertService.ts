import type { UnifiedAlertResource } from '../domain/alerts/resource';

// ---------------------------------------------------------------------------
// Error types
// ---------------------------------------------------------------------------

export type SavedAlertErrorKind =
    | 'duplicate'
    | 'auth'
    | 'validation'
    | 'unknown';

export class SavedAlertServiceError extends Error {
    constructor(
        message: string,
        readonly status: number,
        readonly kind: SavedAlertErrorKind,
    ) {
        super(message);
        this.name = 'SavedAlertServiceError';
    }
}

// ---------------------------------------------------------------------------
// Response types
// ---------------------------------------------------------------------------

export type SavedAlertsResponse = {
    data: UnifiedAlertResource[];
    meta: {
        saved_ids: string[];
        missing_alert_ids: string[];
    };
};

// ---------------------------------------------------------------------------
// Internal helpers (mirrors SavedPlaceService conventions)
// ---------------------------------------------------------------------------

const isRecord = (value: unknown): value is Record<string, unknown> =>
    typeof value === 'object' && value !== null;

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

const errorKindFromStatus = (status: number): SavedAlertErrorKind => {
    if (status === 409) return 'duplicate';
    if (status === 401 || status === 403) return 'auth';
    if (status === 422) return 'validation';
    return 'unknown';
};

const requestHeaders = (): Record<string, string> => {
    const token = csrfToken();
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
    };

    if (token) {
        headers['X-CSRF-TOKEN'] = token;
    }

    return headers;
};

const requestJson = async (
    input: RequestInfo | URL,
    init: RequestInit,
): Promise<unknown> => {
    const response = await fetch(input, {
        credentials: 'same-origin',
        ...init,
    });

    if (!response.ok) {
        throw new SavedAlertServiceError(
            await readErrorMessage(response),
            response.status,
            errorKindFromStatus(response.status),
        );
    }

    return (await response.json()) as unknown;
};

// ---------------------------------------------------------------------------
// Public API
// ---------------------------------------------------------------------------

/**
 * Save an alert for the authenticated user.
 * Throws `SavedAlertServiceError` on failure.
 */
export const saveAlert = async (alertId: string): Promise<void> => {
    await requestJson('/api/saved-alerts', {
        method: 'POST',
        headers: requestHeaders(),
        body: JSON.stringify({ alert_id: alertId }),
    });
};

/**
 * Remove a saved alert for the authenticated user.
 * Throws `SavedAlertServiceError` on failure.
 */
export const removeAlert = async (alertId: string): Promise<void> => {
    const encodedId = encodeURIComponent(alertId);
    await requestJson(`/api/saved-alerts/${encodedId}`, {
        method: 'DELETE',
        headers: requestHeaders(),
    });
};

/**
 * Fetch the full list of saved alerts (hydrated) for the authenticated user.
 * Returns resolved `UnifiedAlertResource` payloads plus metadata about
 * saved IDs and any IDs that could not be resolved.
 * Throws `SavedAlertServiceError` on failure or invalid response shape.
 */
export const fetchSavedAlerts = async (): Promise<SavedAlertsResponse> => {
    const payload = await requestJson('/api/saved-alerts', {
        method: 'GET',
        headers: { Accept: 'application/json' },
    });

    if (
        !isRecord(payload) ||
        !Array.isArray(payload.data) ||
        !isRecord(payload.meta) ||
        !Array.isArray(payload.meta.saved_ids) ||
        !Array.isArray(payload.meta.missing_alert_ids)
    ) {
        throw new SavedAlertServiceError(
            'Invalid API response.',
            500,
            'unknown',
        );
    }

    return {
        data: payload.data as UnifiedAlertResource[],
        meta: {
            saved_ids: payload.meta.saved_ids as string[],
            missing_alert_ids: payload.meta.missing_alert_ids as string[],
        },
    };
};
