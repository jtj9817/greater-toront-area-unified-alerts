export type NotificationInboxItemType = 'alert' | 'digest';

export type NotificationInboxItem = {
    id: number;
    alert_id: string | null;
    type: NotificationInboxItemType;
    delivery_method: string;
    status: string;
    sent_at: string | null;
    read_at: string | null;
    dismissed_at: string | null;
    metadata: Record<string, unknown>;
};

export type NotificationInboxPage = {
    data: NotificationInboxItem[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        unread_count: number;
    };
    links: {
        next: string | null;
        prev: string | null;
    };
};

type NotificationInboxItemResponse = {
    data: unknown;
};

type NotificationInboxPageResponse = {
    data: unknown;
    meta: unknown;
    links: unknown;
};

type MarkAllReadResponse = {
    meta: unknown;
};

type ClearInboxResponse = {
    meta: unknown;
};

export type MarkAllReadResult = {
    marked_read_count: number;
    unread_count: number;
};

export type ClearInboxResult = {
    dismissed_count: number;
    unread_count: number;
};

export class NotificationInboxServiceError extends Error {
    constructor(
        message: string,
        readonly status: number,
    ) {
        super(message);
    }
}

const INBOX_BASE_URL = '/notifications/inbox';

const isRecord = (value: unknown): value is Record<string, unknown> =>
    typeof value === 'object' && value !== null;

const asStringOrNull = (value: unknown): string | null =>
    typeof value === 'string' && value.trim().length > 0 ? value.trim() : null;

const asNumberOr = (value: unknown, fallback: number): number =>
    typeof value === 'number' && Number.isFinite(value) ? value : fallback;

const normalizeType = (value: unknown): NotificationInboxItemType =>
    value === 'digest' ? 'digest' : 'alert';

const normalizeMetadata = (value: unknown): Record<string, unknown> =>
    isRecord(value) ? value : {};

const normalizeItem = (value: unknown): NotificationInboxItem | null => {
    if (!isRecord(value)) {
        return null;
    }

    const id = asNumberOr(value.id, NaN);

    if (!Number.isFinite(id)) {
        return null;
    }

    const alertId =
        value.alert_id === null ? null : asStringOrNull(value.alert_id);

    return {
        id,
        alert_id: alertId,
        type: normalizeType(value.type),
        delivery_method: asStringOrNull(value.delivery_method) ?? 'in_app',
        status: asStringOrNull(value.status) ?? 'sent',
        sent_at: value.sent_at === null ? null : asStringOrNull(value.sent_at),
        read_at: value.read_at === null ? null : asStringOrNull(value.read_at),
        dismissed_at:
            value.dismissed_at === null
                ? null
                : asStringOrNull(value.dismissed_at),
        metadata: normalizeMetadata(value.metadata),
    };
};

const normalizePage = (value: unknown): NotificationInboxPage => {
    if (!isRecord(value)) {
        return {
            data: [],
            meta: {
                current_page: 1,
                last_page: 1,
                per_page: 0,
                total: 0,
                unread_count: 0,
            },
            links: {
                next: null,
                prev: null,
            },
        };
    }

    const rawData = Array.isArray(value.data) ? value.data : [];
    const data = rawData
        .map(normalizeItem)
        .filter((item): item is NotificationInboxItem => item !== null);

    const rawMeta = isRecord(value.meta) ? value.meta : {};
    const rawLinks = isRecord(value.links) ? value.links : {};

    return {
        data,
        meta: {
            current_page: asNumberOr(rawMeta.current_page, 1),
            last_page: asNumberOr(rawMeta.last_page, 1),
            per_page: asNumberOr(rawMeta.per_page, data.length),
            total: asNumberOr(rawMeta.total, data.length),
            unread_count: asNumberOr(rawMeta.unread_count, 0),
        },
        links: {
            next: asStringOrNull(rawLinks.next),
            prev: asStringOrNull(rawLinks.prev),
        },
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

        if (isRecord(payload) && typeof payload.message === 'string') {
            return payload.message;
        }
    } catch {
        return `Request failed (${response.status})`;
    }

    return `Request failed (${response.status})`;
};

const fetchJson = async (
    input: RequestInfo | URL,
    init: RequestInit,
): Promise<unknown> => {
    const response = await fetch(input, {
        credentials: 'same-origin',
        ...init,
    });

    if (!response.ok) {
        throw new NotificationInboxServiceError(
            await readErrorMessage(response),
            response.status,
        );
    }

    return (await response.json()) as unknown;
};

const apiHeaders = (): Record<string, string> => {
    const headers: Record<string, string> = {
        Accept: 'application/json',
    };

    const token = csrfToken();

    if (token) {
        headers['X-CSRF-TOKEN'] = token;
    }

    return headers;
};

const normalizePageUrl = (url: string): string => {
    try {
        const base =
            typeof window !== 'undefined' &&
            typeof window.location?.origin === 'string'
                ? window.location.origin
                : 'http://localhost';
        const parsed = new URL(url, base);

        return `${parsed.pathname}${parsed.search}`;
    } catch {
        return url;
    }
};

export const fetchNotificationInbox = async (options?: {
    includeDismissed?: boolean;
    perPage?: number;
    page?: number;
    pageUrl?: string;
}): Promise<NotificationInboxPage> => {
    if (typeof options?.pageUrl === 'string' && options.pageUrl.trim() !== '') {
        const payload = (await fetchJson(
            normalizePageUrl(options.pageUrl.trim()),
            {
                method: 'GET',
                headers: apiHeaders(),
            },
        )) as NotificationInboxPageResponse;

        return normalizePage(payload);
    }

    const params = new URLSearchParams();

    if (options?.includeDismissed) {
        params.set('include_dismissed', '1');
    }

    if (typeof options?.perPage === 'number' && options.perPage > 0) {
        params.set('per_page', String(Math.floor(options.perPage)));
    }

    if (typeof options?.page === 'number' && options.page > 0) {
        params.set('page', String(Math.floor(options.page)));
    }

    const url =
        params.size > 0
            ? `${INBOX_BASE_URL}?${params.toString()}`
            : INBOX_BASE_URL;

    const payload = (await fetchJson(url, {
        method: 'GET',
        headers: apiHeaders(),
    })) as NotificationInboxPageResponse;

    return normalizePage(payload);
};

export const markNotificationAsRead = async (
    logId: number,
): Promise<NotificationInboxItem> => {
    const payload = (await fetchJson(`${INBOX_BASE_URL}/${logId}/read`, {
        method: 'PATCH',
        headers: {
            ...apiHeaders(),
            'Content-Type': 'application/json',
        },
    })) as NotificationInboxItemResponse;

    const item = normalizeItem(payload.data);

    if (item === null) {
        throw new NotificationInboxServiceError(
            'Invalid inbox response payload.',
            500,
        );
    }

    return item;
};

export const dismissNotification = async (
    logId: number,
): Promise<NotificationInboxItem> => {
    const payload = (await fetchJson(`${INBOX_BASE_URL}/${logId}/dismiss`, {
        method: 'PATCH',
        headers: {
            ...apiHeaders(),
            'Content-Type': 'application/json',
        },
    })) as NotificationInboxItemResponse;

    const item = normalizeItem(payload.data);

    if (item === null) {
        throw new NotificationInboxServiceError(
            'Invalid inbox response payload.',
            500,
        );
    }

    return item;
};

export const markAllNotificationsAsRead =
    async (): Promise<MarkAllReadResult> => {
        const payload = (await fetchJson(`${INBOX_BASE_URL}/read-all`, {
            method: 'PATCH',
            headers: {
                ...apiHeaders(),
                'Content-Type': 'application/json',
            },
        })) as MarkAllReadResponse;

        const meta = isRecord(payload.meta) ? payload.meta : {};

        return {
            marked_read_count: asNumberOr(meta.marked_read_count, 0),
            unread_count: asNumberOr(meta.unread_count, 0),
        };
    };

export const clearNotificationInbox = async (): Promise<ClearInboxResult> => {
    const payload = (await fetchJson(INBOX_BASE_URL, {
        method: 'DELETE',
        headers: {
            ...apiHeaders(),
            'Content-Type': 'application/json',
        },
    })) as ClearInboxResponse;

    const meta = isRecord(payload.meta) ? payload.meta : {};

    return {
        dismissed_count: asNumberOr(meta.dismissed_count, 0),
        unread_count: asNumberOr(meta.unread_count, 0),
    };
};
