import { Link } from '@inertiajs/react';
import React, { useEffect, useMemo, useState } from 'react';
import { login, register } from '@/routes';
import {
    clearNotificationInbox,
    dismissNotification,
    fetchNotificationInbox,
    markNotificationAsRead,
    markAllNotificationsAsRead,
    NotificationInboxServiceError,
    type NotificationInboxItem,
} from '../services/NotificationInboxService';
import { Icon } from './Icon';

type NotificationInboxViewProps = {
    authUserId: number | null;
    onOpenAlert?: (alertId: string) => void;
};

const inboxDateFormatter = new Intl.DateTimeFormat('en-CA', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

const dateTimeLabel = (value: string | null): string => {
    if (!value) {
        return 'Unknown time';
    }

    const parsed = new Date(value);

    if (Number.isNaN(parsed.getTime())) {
        return 'Unknown time';
    }

    return inboxDateFormatter.format(parsed);
};

const sourceLabel = (source: string | null): string => {
    if (!source) {
        return 'GTA Alerts';
    }

    if (source === 'go_transit') {
        return 'GO Transit';
    }

    return source
        .replace('_', ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase());
};

const digestDescription = (item: NotificationInboxItem): string => {
    const total = item.metadata.total_notifications;
    const digestDate = item.metadata.digest_date;

    const totalLabel =
        typeof total === 'number' && Number.isFinite(total)
            ? `${total} alert${total === 1 ? '' : 's'}`
            : 'Daily summary';

    if (typeof digestDate !== 'string' || digestDate.trim() === '') {
        return totalLabel;
    }

    return `${totalLabel} for ${digestDate}`;
};

const alertSummary = (item: NotificationInboxItem): string => {
    const summary = item.metadata.summary;

    if (typeof summary === 'string' && summary.trim().length > 0) {
        return summary.trim();
    }

    return 'Alert details unavailable.';
};

const mergeItemsById = (
    existingItems: NotificationInboxItem[],
    incomingItems: NotificationInboxItem[],
): NotificationInboxItem[] => {
    const itemMap = new Map<number, NotificationInboxItem>();

    for (const item of existingItems) {
        itemMap.set(item.id, item);
    }

    for (const item of incomingItems) {
        itemMap.set(item.id, item);
    }

    return [...itemMap.values()];
};

export const NotificationInboxView: React.FC<NotificationInboxViewProps> = ({
    authUserId,
    onOpenAlert,
}) => {
    const [items, setItems] = useState<NotificationInboxItem[]>([]);
    const [unreadCount, setUnreadCount] = useState(0);
    const [nextPageUrl, setNextPageUrl] = useState<string | null>(null);
    const [isLoading, setIsLoading] = useState<boolean>(authUserId !== null);
    const [isLoadingMore, setIsLoadingMore] = useState(false);
    const [hasLoadedInbox, setHasLoadedInbox] = useState(false);
    const [isMarkingAllRead, setIsMarkingAllRead] = useState(false);
    const [isClearing, setIsClearing] = useState(false);
    const [activeItemId, setActiveItemId] = useState<number | null>(null);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    useEffect(() => {
        if (authUserId === null) {
            setItems([]);
            setUnreadCount(0);
            setNextPageUrl(null);
            setIsLoading(false);
            setIsLoadingMore(false);
            setHasLoadedInbox(false);
            setIsMarkingAllRead(false);
            setErrorMessage(null);
            return;
        }

        let isMounted = true;

        const loadInbox = async (): Promise<void> => {
            setIsLoading(true);
            setHasLoadedInbox(false);
            setNextPageUrl(null);
            setErrorMessage(null);

            try {
                const page = await fetchNotificationInbox({ perPage: 50 });

                if (!isMounted) {
                    return;
                }

                setItems(page.data);
                setUnreadCount(page.meta.unread_count);
                setNextPageUrl(page.links.next);
                setHasLoadedInbox(true);
            } catch (error) {
                if (!isMounted) {
                    return;
                }

                if (
                    error instanceof NotificationInboxServiceError &&
                    error.status === 401
                ) {
                    setErrorMessage(
                        'Please sign in again to view notifications.',
                    );
                } else {
                    setErrorMessage(
                        'Unable to load notifications right now. Please try again shortly.',
                    );
                }
            } finally {
                if (isMounted) {
                    setIsLoading(false);
                }
            }
        };

        void loadInbox();

        return () => {
            isMounted = false;
        };
    }, [authUserId]);

    const hasItems = items.length > 0;
    const hasUnread = unreadCount > 0;
    const hasMoreItems = nextPageUrl !== null;
    const hasInFlightItemOrPagingAction =
        activeItemId !== null || isLoadingMore;

    const sortedItems = useMemo(() => {
        return [...items].sort((left, right) => {
            if (left.sent_at === right.sent_at) {
                return right.id - left.id;
            }

            const leftTime = left.sent_at
                ? new Date(left.sent_at).getTime()
                : 0;
            const rightTime = right.sent_at
                ? new Date(right.sent_at).getTime()
                : 0;

            return rightTime - leftTime;
        });
    }, [items]);

    const markRead = async (logId: number): Promise<void> => {
        if (isMarkingAllRead || isClearing) {
            return;
        }

        setActiveItemId(logId);
        setErrorMessage(null);

        try {
            const updated = await markNotificationAsRead(logId);

            setItems((current) =>
                current.map((item) => (item.id === logId ? updated : item)),
            );
            setUnreadCount((current) => Math.max(0, current - 1));
        } catch {
            setErrorMessage('Unable to mark notification as read.');
        } finally {
            setActiveItemId(null);
        }
    };

    const dismiss = async (logId: number): Promise<void> => {
        if (isMarkingAllRead || isClearing) {
            return;
        }

        setActiveItemId(logId);
        setErrorMessage(null);

        const target = items.find((item) => item.id === logId);
        const wasUnread = target?.read_at === null;

        try {
            await dismissNotification(logId);

            setItems((current) => current.filter((item) => item.id !== logId));

            if (wasUnread) {
                setUnreadCount((current) => Math.max(0, current - 1));
            }
        } catch {
            setErrorMessage('Unable to dismiss notification.');
        } finally {
            setActiveItemId(null);
        }
    };

    const clearAll = async (): Promise<void> => {
        if (isMarkingAllRead || hasInFlightItemOrPagingAction) {
            return;
        }

        const previousItems = items;
        const previousUnreadCount = unreadCount;
        const previousNextPageUrl = nextPageUrl;

        setIsClearing(true);
        setErrorMessage(null);
        setItems([]);
        setUnreadCount(0);
        setNextPageUrl(null);

        try {
            const result = await clearNotificationInbox();
            setUnreadCount(result.unread_count);
        } catch {
            setItems(previousItems);
            setUnreadCount(previousUnreadCount);
            setNextPageUrl(previousNextPageUrl);
            setErrorMessage('Unable to clear notifications.');
        } finally {
            setIsClearing(false);
        }
    };

    const markAllRead = async (): Promise<void> => {
        if (isClearing || isMarkingAllRead || hasInFlightItemOrPagingAction) {
            return;
        }

        const previousItems = items;
        const previousUnreadCount = unreadCount;
        const readAt = new Date().toISOString();

        setIsMarkingAllRead(true);
        setErrorMessage(null);
        setItems((current) =>
            current.map((item) => {
                if (item.read_at !== null || item.dismissed_at !== null) {
                    return item;
                }

                return {
                    ...item,
                    read_at: readAt,
                    status: item.status === 'dismissed' ? item.status : 'read',
                };
            }),
        );
        setUnreadCount(0);

        try {
            const result = await markAllNotificationsAsRead();
            setUnreadCount(result.unread_count);
        } catch {
            setItems(previousItems);
            setUnreadCount(previousUnreadCount);
            setErrorMessage('Unable to mark all notifications as read.');
        } finally {
            setIsMarkingAllRead(false);
        }
    };

    const loadMore = async (): Promise<void> => {
        if (
            !nextPageUrl ||
            isLoadingMore ||
            isMarkingAllRead ||
            isClearing ||
            activeItemId !== null
        ) {
            return;
        }

        setIsLoadingMore(true);
        setErrorMessage(null);

        try {
            const page = await fetchNotificationInbox({
                pageUrl: nextPageUrl,
            });

            setItems((currentItems) => mergeItemsById(currentItems, page.data));
            setUnreadCount(page.meta.unread_count);
            setNextPageUrl(page.links.next);
        } catch {
            setErrorMessage('Unable to load older notifications.');
        } finally {
            setIsLoadingMore(false);
        }
    };

    if (authUserId === null) {
        return (
            <section
                id="gta-alerts-inbox-auth-required"
                className="mx-auto w-full max-w-4xl p-4 md:p-6"
            >
                <div
                    id="gta-alerts-inbox-auth-required-card"
                    className="rounded-2xl border border-white/10 bg-surface-dark p-6 md:p-8"
                >
                    <h2
                        id="gta-alerts-inbox-auth-required-title"
                        className="mb-3 flex items-center gap-3 text-2xl font-bold text-white"
                    >
                        <Icon name="lock" className="text-primary" />
                        Sign in to view your notification inbox
                    </h2>
                    <p className="mb-6 max-w-2xl text-sm text-text-secondary">
                        Your notification center is available to signed-in users
                        so alerts remain private to your account.
                    </p>
                    <div className="flex flex-wrap gap-3">
                        <Link
                            id="gta-alerts-inbox-auth-required-signin-link"
                            href={login().url}
                            className="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white transition hover:bg-primary/90"
                        >
                            <Icon name="login" />
                            Sign in
                        </Link>
                        <Link
                            id="gta-alerts-inbox-auth-required-register-link"
                            href={register().url}
                            className="inline-flex items-center gap-2 rounded-lg border border-white/15 bg-white/5 px-4 py-2 text-sm font-medium text-white transition hover:bg-white/10"
                        >
                            <Icon name="person_add" />
                            Create account
                        </Link>
                    </div>
                </div>
            </section>
        );
    }

    return (
        <section
            id="gta-alerts-inbox-view"
            className="mx-auto w-full max-w-5xl p-4 md:p-6"
        >
            <div
                id="gta-alerts-inbox-header"
                className="mb-6 flex flex-wrap items-center justify-between gap-3 border-b border-white/10 pb-5"
            >
                <div>
                    <h2
                        id="gta-alerts-inbox-title"
                        className="mb-1 flex items-center gap-3 text-2xl font-bold text-white"
                    >
                        <Icon
                            name="notifications_active"
                            className="text-primary"
                        />
                        Notification Center
                    </h2>
                    <p className="text-sm text-text-secondary">
                        Review delivered alerts and digest summaries.
                    </p>
                </div>
                <div className="flex items-center gap-3">
                    <span className="rounded-full border border-primary/40 bg-[#FF7F00]/10 px-3 py-1 text-xs font-semibold text-primary">
                        Unread: {unreadCount}
                    </span>
                    <button
                        id="gta-alerts-inbox-mark-all-read-btn"
                        type="button"
                        className="inline-flex items-center gap-2 rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-sm font-medium text-white transition hover:bg-white/10 disabled:cursor-not-allowed disabled:opacity-60"
                        onClick={() => {
                            void markAllRead();
                        }}
                        disabled={
                            !hasUnread ||
                            isMarkingAllRead ||
                            isClearing ||
                            hasInFlightItemOrPagingAction
                        }
                        aria-label="Mark all notifications as read"
                    >
                        <Icon name="mark_email_read" />
                        {isMarkingAllRead ? 'Marking...' : 'Mark all read'}
                    </button>
                    <button
                        id="gta-alerts-inbox-clear-all-btn"
                        type="button"
                        className="inline-flex items-center gap-2 rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-sm font-medium text-white transition hover:bg-white/10 disabled:cursor-not-allowed disabled:opacity-60"
                        onClick={() => {
                            void clearAll();
                        }}
                        disabled={
                            !hasItems ||
                            isClearing ||
                            isMarkingAllRead ||
                            hasInFlightItemOrPagingAction
                        }
                        aria-label="Clear all notifications"
                    >
                        <Icon name="delete_sweep" />
                        {isClearing ? 'Clearing...' : 'Clear all'}
                    </button>
                </div>
            </div>

            {errorMessage && (
                <div className="mb-5 rounded-lg border border-coral/40 bg-coral/10 px-4 py-3 text-sm text-coral">
                    {errorMessage}
                </div>
            )}

            {isLoading && (
                <div className="rounded-xl border border-white/10 bg-surface-dark p-6 text-sm text-text-secondary">
                    Loading notification inbox...
                </div>
            )}

            {!isLoading && hasLoadedInbox && !hasItems && (
                <div className="rounded-xl border border-white/10 bg-surface-dark p-6">
                    <p className="text-sm font-medium text-white">
                        Your inbox is clear.
                    </p>
                    <p className="mt-1 text-sm text-text-secondary">
                        New alerts and daily digest items will appear here.
                    </p>
                </div>
            )}

            {!isLoading && hasItems && (
                <div id="gta-alerts-inbox-list" className="space-y-3">
                    {sortedItems.map((item) => {
                        const isDigest = item.type === 'digest';
                        const itemSource = sourceLabel(
                            typeof item.metadata.source === 'string'
                                ? item.metadata.source
                                : null,
                        );
                        const alertId = item.alert_id;
                        const canOpenAlert =
                            !isDigest &&
                            typeof alertId === 'string' &&
                            alertId.trim().length > 0;
                        const isUnread = item.read_at === null;
                        const isBusy =
                            activeItemId === item.id || isMarkingAllRead;
                        const summaryText = isDigest
                            ? digestDescription(item)
                            : alertSummary(item);

                        return (
                            <article
                                id={`gta-alerts-inbox-item-${item.id}`}
                                key={item.id}
                                className={`rounded-xl border bg-surface-dark p-4 ${
                                    isUnread
                                        ? 'border-primary/40 shadow-[0_0_0_1px_rgba(59,130,246,0.25)]'
                                        : 'border-white/10'
                                }`}
                            >
                                <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                                    <div className="flex items-center gap-2">
                                        <span
                                            className={`rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase ${
                                                isDigest
                                                    ? 'bg-amber/20 text-amber'
                                                    : 'bg-white/10 text-white'
                                            }`}
                                        >
                                            {isDigest
                                                ? 'Daily Digest'
                                                : itemSource}
                                        </span>
                                        {isUnread && (
                                            <span className="rounded-full bg-[#FF7F00]/20 px-2 py-0.5 text-[11px] font-semibold text-primary uppercase">
                                                Unread
                                            </span>
                                        )}
                                    </div>
                                    <time className="text-xs text-text-secondary">
                                        {dateTimeLabel(item.sent_at)}
                                    </time>
                                </div>

                                <p className="mb-3 text-sm leading-snug font-medium text-white">
                                    {canOpenAlert ? (
                                        <button
                                            id={`gta-alerts-inbox-item-${item.id}-open-alert-btn`}
                                            type="button"
                                            className="text-left hover:text-primary hover:underline"
                                            onClick={() => {
                                                if (alertId) {
                                                    onOpenAlert?.(alertId);
                                                }
                                            }}
                                        >
                                            {summaryText}
                                        </button>
                                    ) : (
                                        summaryText
                                    )}
                                </p>

                                <div className="flex flex-wrap gap-2">
                                    {isUnread && (
                                        <button
                                            id={`gta-alerts-inbox-item-${item.id}-mark-read-btn`}
                                            type="button"
                                            className="inline-flex items-center gap-1 rounded-md border border-white/20 px-2.5 py-1.5 text-xs text-white transition hover:bg-white/10 disabled:cursor-not-allowed disabled:opacity-60"
                                            onClick={() => {
                                                void markRead(item.id);
                                            }}
                                            disabled={isBusy}
                                            aria-label="Mark notification as read"
                                        >
                                            <Icon name="mark_email_read" />
                                            Mark as read
                                        </button>
                                    )}
                                    <button
                                        id={`gta-alerts-inbox-item-${item.id}-dismiss-btn`}
                                        type="button"
                                        className="inline-flex items-center gap-1 rounded-md border border-coral/40 px-2.5 py-1.5 text-xs text-coral transition hover:bg-coral/10 disabled:cursor-not-allowed disabled:opacity-60"
                                        onClick={() => {
                                            void dismiss(item.id);
                                        }}
                                        disabled={isBusy}
                                        aria-label="Dismiss notification"
                                    >
                                        <Icon name="close" />
                                        Dismiss
                                    </button>
                                </div>
                            </article>
                        );
                    })}
                    {hasMoreItems && (
                        <div className="pt-2">
                            <button
                                id="gta-alerts-inbox-load-more-btn"
                                type="button"
                                className="inline-flex items-center gap-2 rounded-lg border border-white/20 bg-white/5 px-3 py-2 text-sm font-medium text-white transition hover:bg-white/10 disabled:cursor-not-allowed disabled:opacity-60"
                                onClick={() => {
                                    void loadMore();
                                }}
                                disabled={
                                    isLoadingMore ||
                                    isMarkingAllRead ||
                                    isClearing ||
                                    activeItemId !== null
                                }
                            >
                                <Icon name="expand_more" />
                                {isLoadingMore
                                    ? 'Loading older notifications...'
                                    : 'Load older notifications'}
                            </button>
                        </div>
                    )}
                </div>
            )}
        </section>
    );
};
