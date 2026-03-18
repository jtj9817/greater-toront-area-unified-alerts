import React, { useEffect } from 'react';
import type { SavedAlertFeedback } from '../hooks/useSavedAlerts';
import { Icon } from './Icon';

const AUTO_DISMISS_MS = 4500;

type SavedAlertActionToastProps = {
    feedback: SavedAlertFeedback | null;
    onDismiss: () => void;
};

const appearanceByKind: Record<
    SavedAlertFeedback['kind'],
    {
        icon: string;
        fill: boolean;
        iconColor: string;
        className: string;
        title: string;
    }
> = {
    saved: {
        icon: 'bookmark_added',
        fill: true,
        iconColor: 'text-forest',
        className: 'border-forest/50 bg-forest/15',
        title: 'Saved Alert',
    },
    removed: {
        icon: 'bookmark_remove',
        fill: false,
        iconColor: 'text-text-secondary',
        className: 'border-white/25 bg-white/8',
        title: 'Removed Alert',
    },
    duplicate: {
        icon: 'info',
        fill: true,
        iconColor: 'text-amber',
        className: 'border-amber/50 bg-amber/15',
        title: 'Already Saved',
    },
    limit: {
        icon: 'block',
        fill: false,
        iconColor: 'text-burnt-orange',
        className: 'border-burnt-orange/50 bg-burnt-orange/15',
        title: 'Save Limit Reached',
    },
    auth: {
        icon: 'lock',
        fill: true,
        iconColor: 'text-primary',
        className: 'border-primary/50 bg-primary/15',
        title: 'Sign In Required',
    },
    validation: {
        icon: 'error',
        fill: true,
        iconColor: 'text-coral',
        className: 'border-coral/50 bg-coral/15',
        title: 'Invalid Alert',
    },
    unknown: {
        icon: 'warning',
        fill: false,
        iconColor: 'text-coral',
        className: 'border-coral/50 bg-coral/15',
        title: 'Save Failed',
    },
    error: {
        icon: 'warning',
        fill: false,
        iconColor: 'text-coral',
        className: 'border-coral/50 bg-coral/15',
        title: 'Save Failed',
    },
};

export const SavedAlertActionToast: React.FC<SavedAlertActionToastProps> = ({
    feedback,
    onDismiss,
}) => {
    useEffect(() => {
        if (feedback === null) {
            return;
        }

        const timeoutId = window.setTimeout(() => {
            onDismiss();
        }, AUTO_DISMISS_MS);

        return () => {
            window.clearTimeout(timeoutId);
        };
    }, [feedback, onDismiss]);

    if (feedback === null) {
        return null;
    }

    const appearance = appearanceByKind[feedback.kind];

    return (
        <div
            id="gta-alerts-saved-alert-action-toast-layer"
            className="pointer-events-none fixed top-4 left-4 z-[120] flex w-[min(92vw,360px)] flex-col gap-3"
        >
            <article
                key={feedback.kind + feedback.message}
                id="gta-alerts-saved-alert-action-toast"
                className={`animate-in fade-in slide-in-from-top-2 pointer-events-auto rounded-xl border p-3 shadow-xl backdrop-blur duration-200 ${appearance.className}`}
                role="status"
                aria-live="polite"
            >
                <div className="mb-2 flex items-start justify-between gap-2">
                    <div className="flex items-center gap-2">
                        <Icon
                            name={appearance.icon}
                            fill={appearance.fill}
                            className={appearance.iconColor}
                        />
                        <span className="text-xs font-semibold tracking-wide text-white uppercase">
                            {appearance.title}
                        </span>
                    </div>
                    <button
                        id="gta-alerts-saved-alert-action-toast-dismiss-btn"
                        type="button"
                        onClick={onDismiss}
                        className="rounded px-1 py-0.5 text-xs text-text-secondary transition hover:text-white"
                        aria-label="Dismiss saved alert message"
                    >
                        ✕
                    </button>
                </div>
                <p className="text-sm leading-snug font-medium text-white">
                    {feedback.message}
                </p>
            </article>
        </div>
    );
};
