import { useCallback, useEffect, useState } from 'react';
import {
    SavedAlertServiceError,
    removeAlert as apiRemoveAlert,
    saveAlert as apiSaveAlert,
} from '../services/SavedAlertService';

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

/** Versioned localStorage key for guest-mode saved alert IDs. */
export const GUEST_STORAGE_KEY = 'gta_saved_alerts_v1';

/** Maximum number of alerts a guest user can save. */
export const GUEST_CAP = 10;

/** Number of oldest entries removed by evictOldestThree. */
const EVICT_COUNT = 3;

// ---------------------------------------------------------------------------
// Feedback types
// ---------------------------------------------------------------------------

export type SavedAlertFeedbackKind =
    | 'saved'
    | 'removed'
    | 'duplicate'
    | 'limit'
    | 'auth'
    | 'validation'
    | 'error';

/**
 * Inline status feedback for save/remove/limit/error events.
 *
 * Feedback is returned as part of the hook state so components can render it
 * inline or as a status message — deliberately separate from the realtime
 * NotificationToastLayer, which serves a different backend event stream.
 */
export type SavedAlertFeedback = {
    kind: SavedAlertFeedbackKind;
    message: string;
    alertId?: string;
};

// ---------------------------------------------------------------------------
// Hook types
// ---------------------------------------------------------------------------

interface UseSavedAlertsOptions {
    /** null = guest (localStorage), non-null = authenticated (API). */
    authUserId: number | null;
    /** Bootstrap IDs injected via Inertia props for authenticated users. */
    initialSavedIds: string[];
}

export interface UseSavedAlertsReturn {
    /** Current set of saved alert IDs in insertion order. */
    savedIds: string[];
    /** Returns true when the given alert ID is in the saved set. */
    isSaved: (alertId: string) => boolean;
    /** Returns true while an auth API call is in-flight for this alert. */
    isPending: (alertId: string) => boolean;
    /** True when a guest user has reached the 10-alert cap. */
    guestCapReached: boolean;
    /** Save an alert. Guest: localStorage. Auth: POST /api/saved-alerts. */
    saveAlert: (alertId: string) => Promise<void>;
    /** Remove a saved alert. Guest: localStorage. Auth: DELETE /api/saved-alerts/{id}. */
    removeAlert: (alertId: string) => Promise<void>;
    /** Toggles the saved state of the given alert. */
    toggleAlert: (alertId: string) => Promise<void>;
    /**
     * Removes the 3 oldest guest-saved IDs (no-op in auth mode).
     * Used as the one-click action when the guest cap is reached.
     */
    evictOldestThree: () => void;
    /** Latest action feedback, or null if no pending feedback. */
    feedback: SavedAlertFeedback | null;
    /** Clears the current feedback message. */
    clearFeedback: () => void;
}

// ---------------------------------------------------------------------------
// localStorage helpers (SSR-safe)
// ---------------------------------------------------------------------------

function readGuestIds(): string[] {
    if (typeof window === 'undefined') {
        return [];
    }

    try {
        const raw = localStorage.getItem(GUEST_STORAGE_KEY);

        if (!raw) return [];

        const parsed: unknown = JSON.parse(raw);

        if (!Array.isArray(parsed)) return [];

        return parsed.filter(
            (item): item is string => typeof item === 'string',
        );
    } catch {
        return [];
    }
}

function writeGuestIds(ids: string[]): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        localStorage.setItem(GUEST_STORAGE_KEY, JSON.stringify(ids));
    } catch {
        // localStorage may be unavailable (private browsing, quota exceeded).
    }
}

// ---------------------------------------------------------------------------
// Hook
// ---------------------------------------------------------------------------

/**
 * useSavedAlerts — centralised saved-alert state layer.
 *
 * Branches on authUserId:
 *   - null  → guest mode: IDs stored in localStorage under `gta_saved_alerts_v1`.
 *   - non-null → auth mode: IDs bootstrapped from Inertia props; mutations go
 *                through /api/saved-alerts with optimistic local updates.
 *
 * Feedback is exposed as an inline state value rather than a global toast so
 * components can render it contextually without coupling to the realtime
 * NotificationToastLayer.
 */
export function useSavedAlerts({
    authUserId,
    initialSavedIds,
}: UseSavedAlertsOptions): UseSavedAlertsReturn {
    const isGuest = authUserId === null;

    const [savedIds, setSavedIds] = useState<string[]>(() => {
        if (isGuest) {
            // SSR returns [] here; client hydrates correctly from localStorage.
            return readGuestIds();
        }

        return initialSavedIds;
    });

    const [pendingIds, setPendingIds] = useState<Set<string>>(new Set());
    const [feedback, setFeedback] = useState<SavedAlertFeedback | null>(null);

    // Keep guest localStorage in sync whenever savedIds changes.
    useEffect(() => {
        if (isGuest) {
            writeGuestIds(savedIds);
        }
    }, [isGuest, savedIds]);

    const guestCapReached = isGuest && savedIds.length >= GUEST_CAP;

    const isSaved = useCallback(
        (alertId: string): boolean => savedIds.includes(alertId),
        [savedIds],
    );

    const isPending = useCallback(
        (alertId: string): boolean => pendingIds.has(alertId),
        [pendingIds],
    );

    const clearFeedback = useCallback(() => setFeedback(null), []);

    // -----------------------------------------------------------------------
    // saveAlert
    // -----------------------------------------------------------------------

    const saveAlert = useCallback(
        async (alertId: string): Promise<void> => {
            // Idempotent — no API call needed for a locally-known duplicate.
            if (savedIds.includes(alertId)) {
                setFeedback({
                    kind: 'duplicate',
                    message: 'This alert is already saved.',
                    alertId,
                });
                return;
            }

            if (isGuest) {
                if (savedIds.length >= GUEST_CAP) {
                    setFeedback({
                        kind: 'limit',
                        message: `You can save up to ${GUEST_CAP} alerts. Remove some to continue.`,
                        alertId,
                    });
                    return;
                }

                setSavedIds((current) => [...current, alertId]);
                setFeedback({ kind: 'saved', message: 'Alert saved.', alertId });
                return;
            }

            // Auth mode — optimistic update then API call.
            setSavedIds((current) => [...current, alertId]);
            setPendingIds((current) => new Set([...current, alertId]));

            try {
                await apiSaveAlert(alertId);
                setFeedback({ kind: 'saved', message: 'Alert saved.', alertId });
            } catch (err) {
                if (
                    err instanceof SavedAlertServiceError &&
                    err.kind === 'duplicate'
                ) {
                    // Server confirms it's already there — keep the optimistic state.
                    setFeedback({
                        kind: 'duplicate',
                        message: 'This alert is already saved.',
                        alertId,
                    });
                } else {
                    // Roll back the optimistic add.
                    setSavedIds((current) =>
                        current.filter((id) => id !== alertId),
                    );

                    if (err instanceof SavedAlertServiceError) {
                        setFeedback({
                            kind: err.kind,
                            message: err.message,
                            alertId,
                        });
                    } else {
                        setFeedback({
                            kind: 'error',
                            message: 'Failed to save alert. Please try again.',
                            alertId,
                        });
                    }
                }
            } finally {
                setPendingIds((current) => {
                    const next = new Set(current);
                    next.delete(alertId);
                    return next;
                });
            }
        },
        [isGuest, savedIds],
    );

    // -----------------------------------------------------------------------
    // removeAlert
    // -----------------------------------------------------------------------

    const removeAlert = useCallback(
        async (alertId: string): Promise<void> => {
            if (!savedIds.includes(alertId)) {
                return;
            }

            if (isGuest) {
                setSavedIds((current) =>
                    current.filter((id) => id !== alertId),
                );
                setFeedback({
                    kind: 'removed',
                    message: 'Alert removed.',
                    alertId,
                });
                return;
            }

            // Auth mode — optimistic update then API call.
            setSavedIds((current) => current.filter((id) => id !== alertId));
            setPendingIds((current) => new Set([...current, alertId]));

            try {
                await apiRemoveAlert(alertId);
                setFeedback({
                    kind: 'removed',
                    message: 'Alert removed.',
                    alertId,
                });
            } catch (err) {
                // Roll back the optimistic remove.
                setSavedIds((current) =>
                    current.includes(alertId)
                        ? current
                        : [...current, alertId],
                );

                if (err instanceof SavedAlertServiceError) {
                    setFeedback({
                        kind: err.kind,
                        message: err.message,
                        alertId,
                    });
                } else {
                    setFeedback({
                        kind: 'error',
                        message: 'Failed to remove alert. Please try again.',
                        alertId,
                    });
                }
            } finally {
                setPendingIds((current) => {
                    const next = new Set(current);
                    next.delete(alertId);
                    return next;
                });
            }
        },
        [isGuest, savedIds],
    );

    // -----------------------------------------------------------------------
    // toggleAlert
    // -----------------------------------------------------------------------

    const toggleAlert = useCallback(
        async (alertId: string): Promise<void> => {
            if (savedIds.includes(alertId)) {
                await removeAlert(alertId);
            } else {
                await saveAlert(alertId);
            }
        },
        [saveAlert, removeAlert, savedIds],
    );

    // -----------------------------------------------------------------------
    // evictOldestThree
    // -----------------------------------------------------------------------

    const evictOldestThree = useCallback(() => {
        if (!isGuest) {
            return;
        }

        setSavedIds((current) => current.slice(EVICT_COUNT));
        setFeedback({ kind: 'removed', message: 'Oldest 3 alerts removed.' });
    }, [isGuest]);

    return {
        savedIds,
        isSaved,
        isPending,
        guestCapReached,
        saveAlert,
        removeAlert,
        toggleAlert,
        evictOldestThree,
        feedback,
        clearFeedback,
    };
}
