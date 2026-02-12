import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { NotificationInboxView } from './NotificationInboxView';

type FetchMock = ReturnType<typeof vi.fn>;

function mockJsonResponse(payload: unknown, ok = true, status = 200): Response {
    return {
        ok,
        status,
        json: async () => payload,
    } as unknown as Response;
}

describe('NotificationInboxView', () => {
    beforeEach(() => {
        vi.stubGlobal('fetch', vi.fn());
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('shows sign-in UX for guest users without fetching inbox data', () => {
        const fetchMock = globalThis.fetch as FetchMock;

        render(<NotificationInboxView authUserId={null} />);

        expect(
            screen.getByText('Sign in to view your notification inbox'),
        ).toBeInTheDocument();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('loads inbox entries and renders both digest and standard alert items', async () => {
        const fetchMock = globalThis.fetch as FetchMock;

        fetchMock.mockResolvedValueOnce(
            mockJsonResponse({
                data: [
                    {
                        id: 22,
                        alert_id: 'digest:2026-02-10',
                        type: 'digest',
                        delivery_method: 'in_app_digest',
                        status: 'sent',
                        sent_at: '2026-02-10T12:00:00+00:00',
                        read_at: null,
                        dismissed_at: null,
                        metadata: {
                            type: 'daily_digest',
                            digest_date: '2026-02-10',
                            total_notifications: 3,
                        },
                    },
                    {
                        id: 11,
                        alert_id: 'police:11',
                        type: 'alert',
                        delivery_method: 'in_app',
                        status: 'delivered',
                        sent_at: '2026-02-10T11:00:00+00:00',
                        read_at: null,
                        dismissed_at: null,
                        metadata: {
                            source: 'police',
                            summary: 'Police response in progress',
                            severity: 'major',
                        },
                    },
                ],
                meta: {
                    current_page: 1,
                    last_page: 1,
                    per_page: 50,
                    total: 2,
                    unread_count: 2,
                },
                links: {
                    next: null,
                    prev: null,
                },
            }),
        );

        render(<NotificationInboxView authUserId={99} />);

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalledTimes(1);
        });

        expect(screen.getByText('Unread: 2')).toBeInTheDocument();
        expect(screen.getByText('Daily Digest')).toBeInTheDocument();
        expect(
            screen.getByText('3 alerts for 2026-02-10'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Police response in progress'),
        ).toBeInTheDocument();
    });

    it('loads older notifications when the load more control is used', async () => {
        const fetchMock = globalThis.fetch as FetchMock;

        fetchMock.mockResolvedValueOnce(
            mockJsonResponse({
                data: [
                    {
                        id: 21,
                        alert_id: 'police:21',
                        type: 'alert',
                        delivery_method: 'in_app',
                        status: 'delivered',
                        sent_at: '2026-02-10T12:00:00+00:00',
                        read_at: null,
                        dismissed_at: null,
                        metadata: {
                            source: 'police',
                            summary: 'Police response near Main Street',
                        },
                    },
                ],
                meta: {
                    current_page: 1,
                    last_page: 2,
                    per_page: 50,
                    total: 2,
                    unread_count: 2,
                },
                links: {
                    next: 'https://example.test/notifications/inbox?page=2&per_page=50&include_dismissed=1',
                    prev: null,
                },
            }),
        );

        fetchMock.mockResolvedValueOnce(
            mockJsonResponse({
                data: [
                    {
                        id: 10,
                        alert_id: 'fire:10',
                        type: 'alert',
                        delivery_method: 'in_app',
                        status: 'delivered',
                        sent_at: '2026-02-10T10:00:00+00:00',
                        read_at: null,
                        dismissed_at: null,
                        metadata: {
                            source: 'fire',
                            summary: 'Structure fire under control',
                        },
                    },
                ],
                meta: {
                    current_page: 2,
                    last_page: 2,
                    per_page: 50,
                    total: 2,
                    unread_count: 2,
                },
                links: {
                    next: null,
                    prev: '/notifications/inbox?page=1&per_page=50',
                },
            }),
        );

        render(<NotificationInboxView authUserId={99} />);

        await screen.findByText('Police response near Main Street');

        fireEvent.click(
            screen.getByRole('button', { name: /Load older notifications/i }),
        );

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalledTimes(2);
        });

        const secondRequest = fetchMock.mock.calls[1] as [string, RequestInit];
        expect(secondRequest[0]).toBe(
            '/notifications/inbox?page=2&per_page=50&include_dismissed=1',
        );
        expect(secondRequest[1].method).toBe('GET');

        expect(
            await screen.findByText('Structure fire under control'),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /Load older notifications/i }),
        ).not.toBeInTheDocument();
    });

    it('calls onOpenAlert when alert summary is selected', async () => {
        const fetchMock = globalThis.fetch as FetchMock;
        const onOpenAlert = vi.fn();

        fetchMock.mockResolvedValueOnce(
            mockJsonResponse({
                data: [
                    {
                        id: 31,
                        alert_id: 'police:31',
                        type: 'alert',
                        delivery_method: 'in_app',
                        status: 'delivered',
                        sent_at: '2026-02-10T13:00:00+00:00',
                        read_at: null,
                        dismissed_at: null,
                        metadata: {
                            source: 'police',
                            summary: 'Road closure due to police activity',
                        },
                    },
                    {
                        id: 32,
                        alert_id: 'digest:2026-02-10',
                        type: 'digest',
                        delivery_method: 'in_app_digest',
                        status: 'sent',
                        sent_at: '2026-02-10T14:00:00+00:00',
                        read_at: null,
                        dismissed_at: null,
                        metadata: {
                            type: 'daily_digest',
                            digest_date: '2026-02-10',
                            total_notifications: 5,
                        },
                    },
                ],
                meta: {
                    current_page: 1,
                    last_page: 1,
                    per_page: 50,
                    total: 2,
                    unread_count: 2,
                },
                links: {
                    next: null,
                    prev: null,
                },
            }),
        );

        render(<NotificationInboxView authUserId={99} onOpenAlert={onOpenAlert} />);

        fireEvent.click(await screen.findByText('Road closure due to police activity'));

        expect(onOpenAlert).toHaveBeenCalledWith('police:31');
        expect(onOpenAlert).toHaveBeenCalledTimes(1);
    });

    it('does not call onOpenAlert for digest summaries', async () => {
        const fetchMock = globalThis.fetch as FetchMock;
        const onOpenAlert = vi.fn();

        fetchMock.mockResolvedValueOnce(
            mockJsonResponse({
                data: [
                    {
                        id: 32,
                        alert_id: 'digest:2026-02-10',
                        type: 'digest',
                        delivery_method: 'in_app_digest',
                        status: 'sent',
                        sent_at: '2026-02-10T14:00:00+00:00',
                        read_at: null,
                        dismissed_at: null,
                        metadata: {
                            type: 'daily_digest',
                            digest_date: '2026-02-10',
                            total_notifications: 5,
                        },
                    },
                ],
                meta: {
                    current_page: 1,
                    last_page: 1,
                    per_page: 50,
                    total: 1,
                    unread_count: 1,
                },
                links: {
                    next: null,
                    prev: null,
                },
            }),
        );

        render(<NotificationInboxView authUserId={99} onOpenAlert={onOpenAlert} />);

        fireEvent.click(await screen.findByText('5 alerts for 2026-02-10'));

        expect(onOpenAlert).not.toHaveBeenCalled();
    });

    it('marks notifications as read and dismisses items', async () => {
        const fetchMock = globalThis.fetch as FetchMock;

        fetchMock.mockResolvedValueOnce(
            mockJsonResponse({
                data: [
                    {
                        id: 11,
                        alert_id: 'police:11',
                        type: 'alert',
                        delivery_method: 'in_app',
                        status: 'delivered',
                        sent_at: '2026-02-10T11:00:00+00:00',
                        read_at: null,
                        dismissed_at: null,
                        metadata: {
                            source: 'police',
                            summary: 'Police response in progress',
                        },
                    },
                    {
                        id: 12,
                        alert_id: 'fire:12',
                        type: 'alert',
                        delivery_method: 'in_app',
                        status: 'delivered',
                        sent_at: '2026-02-10T10:00:00+00:00',
                        read_at: null,
                        dismissed_at: null,
                        metadata: {
                            source: 'fire',
                            summary: 'Structure fire update',
                        },
                    },
                ],
                meta: {
                    current_page: 1,
                    last_page: 1,
                    per_page: 50,
                    total: 2,
                    unread_count: 2,
                },
                links: {
                    next: null,
                    prev: null,
                },
            }),
        );

        fetchMock.mockResolvedValueOnce(
            mockJsonResponse({
                data: {
                    id: 11,
                    alert_id: 'police:11',
                    type: 'alert',
                    delivery_method: 'in_app',
                    status: 'read',
                    sent_at: '2026-02-10T11:00:00+00:00',
                    read_at: '2026-02-10T11:05:00+00:00',
                    dismissed_at: null,
                    metadata: {
                        source: 'police',
                        summary: 'Police response in progress',
                    },
                },
            }),
        );

        fetchMock.mockResolvedValueOnce(
            mockJsonResponse({
                data: {
                    id: 12,
                    alert_id: 'fire:12',
                    type: 'alert',
                    delivery_method: 'in_app',
                    status: 'dismissed',
                    sent_at: '2026-02-10T10:00:00+00:00',
                    read_at: '2026-02-10T10:02:00+00:00',
                    dismissed_at: '2026-02-10T10:02:00+00:00',
                    metadata: {
                        source: 'fire',
                        summary: 'Structure fire update',
                    },
                },
            }),
        );

        render(<NotificationInboxView authUserId={99} />);

        await screen.findByText('Police response in progress');

        fireEvent.click(
            screen.getAllByRole('button', {
                name: /Mark notification as read/i,
            })[0],
        );

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalledTimes(2);
        });

        const readRequest = fetchMock.mock.calls[1] as [string, RequestInit];
        expect(readRequest[0]).toBe('/notifications/inbox/11/read');
        expect(readRequest[1].method).toBe('PATCH');
        expect(screen.getByText('Unread: 1')).toBeInTheDocument();

        const fireCard = screen
            .getByText('Structure fire update')
            .closest('article');

        if (!fireCard) {
            throw new Error('Expected fire notification card to exist');
        }

        fireEvent.click(
            within(fireCard).getByRole('button', {
                name: /Dismiss notification/i,
            }),
        );

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalledTimes(3);
        });

        const dismissRequest = fetchMock.mock.calls[2] as [string, RequestInit];
        expect(dismissRequest[0]).toBe('/notifications/inbox/12/dismiss');
        expect(dismissRequest[1].method).toBe('PATCH');

        await waitFor(() => {
            expect(
                screen.queryByText('Structure fire update'),
            ).not.toBeInTheDocument();
        });
    });

    it('clears all notifications from the inbox', async () => {
        const fetchMock = globalThis.fetch as FetchMock;

        fetchMock.mockResolvedValueOnce(
            mockJsonResponse({
                data: [
                    {
                        id: 11,
                        alert_id: 'police:11',
                        type: 'alert',
                        delivery_method: 'in_app',
                        status: 'delivered',
                        sent_at: '2026-02-10T11:00:00+00:00',
                        read_at: null,
                        dismissed_at: null,
                        metadata: {
                            source: 'police',
                            summary: 'Police response in progress',
                        },
                    },
                ],
                meta: {
                    current_page: 1,
                    last_page: 1,
                    per_page: 50,
                    total: 1,
                    unread_count: 1,
                },
                links: {
                    next: null,
                    prev: null,
                },
            }),
        );

        fetchMock.mockResolvedValueOnce(
            mockJsonResponse({
                meta: {
                    dismissed_count: 1,
                    unread_count: 0,
                },
            }),
        );

        render(<NotificationInboxView authUserId={99} />);

        await screen.findByText('Police response in progress');

        fireEvent.click(screen.getByRole('button', { name: /Clear all/i }));

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalledTimes(2);
        });

        const clearRequest = fetchMock.mock.calls[1] as [string, RequestInit];
        expect(clearRequest[0]).toBe('/notifications/inbox');
        expect(clearRequest[1].method).toBe('DELETE');

        await waitFor(() => {
            expect(screen.getByText('Your inbox is clear.')).toBeInTheDocument();
        });
        expect(screen.getByText('Unread: 0')).toBeInTheDocument();
    });

    it('does not show empty-state copy when inbox load fails', async () => {
        const fetchMock = globalThis.fetch as FetchMock;

        fetchMock.mockResolvedValueOnce(
            mockJsonResponse(
                {
                    message: 'Server error',
                },
                false,
                500,
            ),
        );

        render(<NotificationInboxView authUserId={99} />);

        await waitFor(() => {
            expect(
                screen.getByText(
                    'Unable to load notifications right now. Please try again shortly.',
                ),
            ).toBeInTheDocument();
        });

        expect(screen.queryByText('Your inbox is clear.')).not.toBeInTheDocument();
    });
});
