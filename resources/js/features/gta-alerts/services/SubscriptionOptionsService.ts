export type SubscriptionOption = {
    urn: string;
    id: string;
    name: string;
};

export type SubscriptionOptions = {
    agency: {
        urn: string;
        name: string;
    };
    routes: SubscriptionOption[];
    stations: SubscriptionOption[];
    lines: SubscriptionOption[];
};

type SubscriptionOptionsResponse = {
    data: unknown;
};

const isRecord = (value: unknown): value is Record<string, unknown> =>
    typeof value === 'object' && value !== null;

const asString = (value: unknown): string =>
    typeof value === 'string' ? value.trim() : '';

const normalizeOption = (
    value: unknown,
    urnPrefix: string,
): SubscriptionOption | null => {
    if (!isRecord(value)) {
        return null;
    }

    const urn = asString(value.urn);
    const id = asString(value.id || value.slug);
    const name = asString(value.name);

    if (id.length === 0 || name.length === 0) {
        return null;
    }

    return {
        urn:
            urn.length > 0
                ? urn.toLowerCase()
                : `${urnPrefix}:${id}`.toLowerCase(),
        id,
        name,
    };
};

const normalizeOptionsGroup = (
    value: unknown,
    urnPrefix: string,
): SubscriptionOption[] => {
    if (!Array.isArray(value)) {
        return [];
    }

    const options = value
        .map((item) => normalizeOption(item, urnPrefix))
        .filter((item): item is SubscriptionOption => item !== null);

    const deduped = new Map<string, SubscriptionOption>();
    for (const option of options) {
        deduped.set(option.urn, option);
    }

    return Array.from(deduped.values()).sort((left, right) =>
        left.name.localeCompare(right.name, undefined, { numeric: true }),
    );
};

const defaultOptions: SubscriptionOptions = {
    agency: {
        urn: 'agency:ttc',
        name: 'Toronto Transit Commission',
    },
    routes: [],
    stations: [],
    lines: [],
};

const normalizeOptions = (value: unknown): SubscriptionOptions => {
    if (!isRecord(value)) {
        return defaultOptions;
    }

    const agency = isRecord(value.agency)
        ? {
              urn: asString(value.agency.urn).toLowerCase() || 'agency:ttc',
              name: asString(value.agency.name) || defaultOptions.agency.name,
          }
        : defaultOptions.agency;

    return {
        agency,
        routes: normalizeOptionsGroup(value.routes, 'route'),
        stations: normalizeOptionsGroup(value.stations, 'station'),
        lines: normalizeOptionsGroup(value.lines, 'line'),
    };
};

export const fetchSubscriptionOptions =
    async (): Promise<SubscriptionOptions> => {
        const response = await fetch('/api/subscriptions/options', {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
            },
        });

        if (!response.ok) {
            throw new Error(
                `Failed to fetch subscription options (${response.status})`,
            );
        }

        const payload = (await response.json()) as SubscriptionOptionsResponse;

        return normalizeOptions(payload.data);
    };
