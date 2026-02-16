export type SavedPlace = {
    id: number;
    name: string;
    lat: number;
    long: number;
    radius: number;
    type: string;
};

export type GeocodingSearchResult = {
    id: string;
    type: string;
    name: string;
    secondary: string | null;
    lat: number;
    long: number;
};

export class SavedPlaceServiceError extends Error {
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

const normalizeSavedPlace = (value: unknown): SavedPlace | null => {
    if (!isRecord(value)) {
        return null;
    }

    const id = asNumber(value.id);
    const name = asString(value.name);
    const lat = asNumber(value.lat);
    const long = asNumber(value.long);
    const radius = asNumber(value.radius);
    const type = asString(value.type);

    if (
        id === null ||
        name === null ||
        lat === null ||
        long === null ||
        radius === null ||
        type === null
    ) {
        return null;
    }

    return {
        id,
        name,
        lat,
        long,
        radius,
        type,
    };
};

const normalizeGeocodingSearchResult = (
    value: unknown,
): GeocodingSearchResult | null => {
    if (!isRecord(value)) {
        return null;
    }

    const id = asString(value.id);
    const type = asString(value.type);
    const name = asString(value.name);
    const lat = asNumber(value.lat);
    const long = asNumber(value.long);
    const secondary = asString(value.secondary);

    if (
        id === null ||
        type === null ||
        name === null ||
        lat === null ||
        long === null
    ) {
        return null;
    }

    return {
        id,
        type,
        name,
        lat,
        long,
        secondary,
    };
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
        throw new SavedPlaceServiceError(
            await readErrorMessage(response),
            response.status,
        );
    }

    return (await response.json()) as unknown;
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

export const fetchSavedPlaces = async (): Promise<SavedPlace[]> => {
    const payload = await requestJson('/api/saved-places', {
        method: 'GET',
        headers: {
            Accept: 'application/json',
        },
    });

    if (!isRecord(payload) || !Array.isArray(payload.data)) {
        return [];
    }

    return payload.data
        .map(normalizeSavedPlace)
        .filter((item): item is SavedPlace => item !== null);
};

export const searchGeocoding = async (
    query: string,
): Promise<GeocodingSearchResult[]> => {
    const params = new URLSearchParams({ q: query, limit: '8' });
    const payload = await requestJson(
        `/api/geocoding/search?${params.toString()}`,
        {
            method: 'GET',
            headers: {
                Accept: 'application/json',
            },
        },
    );

    if (!isRecord(payload) || !Array.isArray(payload.data)) {
        return [];
    }

    return payload.data
        .map(normalizeGeocodingSearchResult)
        .filter((item): item is GeocodingSearchResult => item !== null);
};

export const createSavedPlace = async (input: {
    name: string;
    lat: number;
    long: number;
    radius: number;
    type: string;
}): Promise<SavedPlace> => {
    const payload = await requestJson('/api/saved-places', {
        method: 'POST',
        headers: requestHeaders(),
        body: JSON.stringify(input),
    });

    if (!isRecord(payload) || !('data' in payload)) {
        throw new SavedPlaceServiceError('Invalid API response.', 500);
    }

    const place = normalizeSavedPlace(payload.data);

    if (place === null) {
        throw new SavedPlaceServiceError('Invalid API response.', 500);
    }

    return place;
};

export const deleteSavedPlace = async (savedPlaceId: number): Promise<void> => {
    await requestJson(`/api/saved-places/${savedPlaceId}`, {
        method: 'DELETE',
        headers: requestHeaders(),
    });
};
