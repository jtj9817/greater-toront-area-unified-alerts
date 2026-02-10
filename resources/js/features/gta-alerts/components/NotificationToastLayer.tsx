import React, { useCallback, useEffect, useRef, useState } from 'react';
import { z } from 'zod';
import { Icon } from './Icon';

const TOAST_EVENT = '.alert.notification.sent';
const MAX_TOASTS = 4;
const TOAST_TIMEOUT_MS = 9000;

const NotificationToastSchema = z.object({
    alert_id: z.string().min(1),
    source: z.string().min(1),
    severity: z.string().min(1),
    summary: z.string().min(1),
    sent_at: z.string().min(1),
});

type NotificationToastPayload = z.infer<typeof NotificationToastSchema>;

type NotificationToast = NotificationToastPayload & {
    id: string;
};

const severityStyle = (severity: string): string => {
    const normalized = severity.toLowerCase();

    if (normalized === 'critical') {
        return 'border-coral/50 bg-coral/15';
    }

    if (normalized === 'major') {
        return 'border-amber/50 bg-amber/15';
    }

    if (normalized === 'minor') {
        return 'border-forest/50 bg-forest/15';
    }

    return 'border-white/15 bg-white/10';
};

const sourceLabel = (source: string): string => {
    if (source === 'go_transit') {
        return 'GO Transit';
    }

    return source.replace('_', ' ').replace(/\b\w/g, (char) => char.toUpperCase());
};

const relativeTimeLabel = (sentAt: string): string => {
    const sentAtDate = new Date(sentAt);

    if (Number.isNaN(sentAtDate.getTime())) {
        return 'Just now';
    }

    const deltaMs = Date.now() - sentAtDate.getTime();
    const deltaMinutes = Math.floor(deltaMs / (1000 * 60));

    if (deltaMinutes <= 0) {
        return 'Just now';
    }

    if (deltaMinutes < 60) {
        return `${deltaMinutes}m ago`;
    }

    const deltaHours = Math.floor(deltaMinutes / 60);
    return `${deltaHours}h ago`;
};

type NotificationToastLayerProps = {
    authUserId: number | null;
};

export const NotificationToastLayer: React.FC<NotificationToastLayerProps> = ({
    authUserId,
}) => {
    const [toasts, setToasts] = useState<NotificationToast[]>([]);
    const timersRef = useRef<Record<string, number>>({});

    const dismissToast = useCallback((toastId: string): void => {
        const timerId = timersRef.current[toastId];
        if (timerId !== undefined) {
            window.clearTimeout(timerId);
            delete timersRef.current[toastId];
        }

        setToasts((currentToasts) =>
            currentToasts.filter((toast) => toast.id !== toastId),
        );
    }, []);

    useEffect(() => {
        const timers = timersRef.current;

        return () => {
            Object.values(timers).forEach((timerId) =>
                window.clearTimeout(timerId),
            );
        };
    }, []);

    useEffect(() => {
        if (authUserId === null || typeof window === 'undefined' || !window.Echo) {
            return;
        }

        const channelName = `users.${authUserId}.notifications`;
        const channel = window.Echo.private(channelName);

        channel.listen(TOAST_EVENT, (payload: unknown) => {
            const result = NotificationToastSchema.safeParse(payload);

            if (!result.success) {
                return;
            }

            const normalizedPayload = result.data;

            const toastId = `${normalizedPayload.alert_id}-${Date.now()}-${Math.random()
                .toString(36)
                .slice(2)}`;
            const toast: NotificationToast = {
                id: toastId,
                ...normalizedPayload,
            };

            setToasts((currentToasts) => [toast, ...currentToasts].slice(0, MAX_TOASTS));

            const timerId = window.setTimeout(() => {
                dismissToast(toastId);
            }, TOAST_TIMEOUT_MS);
            timersRef.current[toastId] = timerId;
        });

        return () => {
            channel.stopListening(TOAST_EVENT);
            window.Echo?.leave(channelName);
        };
    }, [authUserId, dismissToast]);

    if (toasts.length === 0) {
        return null;
    }

    return (
        <div className="pointer-events-none fixed top-4 right-4 z-[120] flex w-[min(92vw,360px)] flex-col gap-3">
            {toasts.map((toast) => (
                <article
                    key={toast.id}
                    className={`pointer-events-auto rounded-xl border p-3 shadow-xl backdrop-blur ${severityStyle(
                        toast.severity,
                    )}`}
                    role="status"
                >
                    <div className="mb-2 flex items-start justify-between gap-2">
                        <div className="flex items-center gap-2">
                            <Icon name="notifications" className="text-primary" />
                            <span className="text-xs font-semibold tracking-wide text-white uppercase">
                                {sourceLabel(toast.source)}
                            </span>
                        </div>
                        <button
                            type="button"
                            onClick={() => dismissToast(toast.id)}
                            className="rounded px-1 py-0.5 text-xs text-text-secondary transition hover:text-white"
                            aria-label={`Dismiss notification ${toast.summary}`}
                        >
                            ✕
                        </button>
                    </div>
                    <p className="mb-2 text-sm leading-snug font-medium text-white">
                        {toast.summary}
                    </p>
                    <div className="flex items-center justify-between text-xs text-text-secondary">
                        <span className="font-medium uppercase">
                            {toast.severity}
                        </span>
                        <span>{relativeTimeLabel(toast.sent_at)}</span>
                    </div>
                </article>
            ))}
        </div>
    );
};
