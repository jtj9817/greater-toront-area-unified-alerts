import React, { useEffect, useMemo, useState } from 'react';
import type { DomainAlert } from '../domain/alerts';
import { AlertService } from '../services/AlertService';
import { fetchSavedAlerts } from '../services/SavedAlertService';
import { AlertCard } from './AlertCard';
import { Icon } from './Icon';

interface SavedViewProps {
    authUserId: number | null;
    onSelectAlert: (id: string) => void;
    allAlerts?: DomainAlert[];
    savedIds: string[];
    isSaved: (id: string) => boolean;
    isPending: (id: string) => boolean;
    onToggleSave: (id: string) => Promise<void>;
    guestCapReached?: boolean;
    onEvictOldest?: () => void;
}

export const SavedView: React.FC<SavedViewProps> = ({
    authUserId,
    onSelectAlert,
    allAlerts = [],
    savedIds,
    isSaved,
    isPending,
    onToggleSave,
    guestCapReached = false,
    onEvictOldest,
}) => {
    const isGuest = authUserId === null;

    // Authenticated state: fetch hydrated alerts from API
    const [authSavedAlerts, setAuthSavedAlerts] = useState<DomainAlert[]>([]);
    const [missingIds, setMissingIds] = useState<string[]>([]);
    const [isLoading, setIsLoading] = useState(!isGuest);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (isGuest) {
            setIsLoading(false);
            return;
        }

        let isMounted = true;
        const loadSaved = async () => {
            setIsLoading(true);
            setError(null);
            try {
                const response = await fetchSavedAlerts();
                if (isMounted) {
                    setAuthSavedAlerts(
                        AlertService.mapUnifiedAlertsToDomainAlerts(
                            response.data,
                        ),
                    );
                    setMissingIds(response.meta.missing_alert_ids);
                }
            } catch {
                if (isMounted) {
                    setError('Failed to load saved alerts.');
                }
            } finally {
                if (isMounted) {
                    setIsLoading(false);
                }
            }
        };

        loadSaved();
        return () => {
            isMounted = false;
        };
    }, [isGuest]);

    // Guest state: reconcile local IDs with alerts we already know about
    const guestSavedAlerts = useMemo(() => {
        if (!isGuest) return [];

        // Map savedIds to DomainAlerts if we have them in allAlerts
        return savedIds
            .map((id) => allAlerts.find((a) => a.id === id))
            .filter((a): a is DomainAlert => a !== undefined);
    }, [isGuest, savedIds, allAlerts]);

    const authDisplayAlerts = useMemo(() => {
        if (isGuest) return [];
        return authSavedAlerts.filter((item) => isSaved(item.id));
    }, [authSavedAlerts, isGuest, isSaved]);

    const displayAlerts = isGuest ? guestSavedAlerts : authDisplayAlerts;

    // Filter missing IDs for guest mode (saved but not in current feed)
    const guestMissingIds = useMemo(() => {
        if (!isGuest) return [];
        return savedIds.filter((id) => !allAlerts.some((a) => a.id === id));
    }, [isGuest, savedIds, allAlerts]);

    const authMissingIds = useMemo(() => {
        if (isGuest) return [];
        return missingIds.filter((id) => isSaved(id));
    }, [isGuest, isSaved, missingIds]);

    const activeMissingIds = isGuest ? guestMissingIds : authMissingIds;

    const isEmpty =
        !isLoading &&
        error === null &&
        displayAlerts.length === 0 &&
        activeMissingIds.length === 0;

    return (
        <section id="gta-alerts-saved-view" className="p-4 md:p-6">
            <div
                id="gta-alerts-saved-view-header"
                className="mx-auto mb-8 max-w-3xl"
            >
                <h2
                    id="gta-alerts-saved-view-title"
                    className="mb-2 flex items-center gap-3 text-2xl font-bold text-white"
                >
                    <Icon name="bookmark" className="text-primary" />
                    Saved Alerts
                </h2>
                <p
                    id="gta-alerts-saved-view-description"
                    className="text-sm text-text-secondary"
                >
                    {isGuest
                        ? 'Your alerts are saved locally on this device.'
                        : "Review incidents you've flagged for monitoring."}
                </p>
            </div>

            {guestCapReached && isGuest && (
                <div
                    id="gta-alerts-saved-view-guest-cap"
                    className="panel-shadow mx-auto mb-8 flex max-w-3xl items-center justify-between border-2 border-black bg-[#FF7F00]/20 p-4"
                >
                    <div className="flex items-center gap-3">
                        <Icon name="warning" className="text-[#FF7F00]" />
                        <p className="text-sm font-bold text-white">
                            Guest limit reached (10 alerts). Remove some to save
                            more.
                        </p>
                    </div>
                    <button
                        onClick={onEvictOldest}
                        className="border border-[#FF7F00] bg-transparent px-3 py-1.5 text-xs font-bold text-[#FF7F00] transition-colors hover:bg-[#FF7F00] hover:text-black"
                    >
                        Clear Oldest 3
                    </button>
                </div>
            )}

            <div
                id="gta-alerts-saved-view-list"
                className="mx-auto flex w-full max-w-5xl flex-col gap-4 md:gap-6"
            >
                {isLoading ? (
                    <div className="flex flex-col items-center justify-center py-20">
                        <Icon
                            name="sync"
                            className="mb-4 animate-spin text-4xl text-primary"
                        />
                        <p className="text-text-secondary">
                            Loading your saved alerts...
                        </p>
                    </div>
                ) : error ? (
                    <div className="flex flex-col items-center justify-center py-20 text-center">
                        <div className="mb-4 flex h-20 w-20 items-center justify-center rounded-full bg-white/5">
                            <Icon
                                name="warning"
                                className="text-4xl text-text-secondary opacity-30"
                            />
                        </div>
                        <h3 className="mb-2 text-xl font-bold text-white">
                            Unable to load saved alerts
                        </h3>
                        <p className="max-w-xs text-sm text-text-secondary">
                            {error}
                        </p>
                    </div>
                ) : isEmpty ? (
                    <div className="flex flex-col items-center justify-center py-20 text-center">
                        <div className="mb-4 flex h-20 w-20 items-center justify-center rounded-full bg-white/5">
                            <Icon
                                name="bookmark_border"
                                className="text-4xl text-text-secondary opacity-30"
                            />
                        </div>
                        <h3 className="mb-2 text-xl font-bold text-white">
                            No saved alerts
                        </h3>
                        <p className="max-w-xs text-sm text-text-secondary">
                            Incidents you save will appear here for quick
                            access.
                        </p>
                    </div>
                ) : (
                    <>
                        {displayAlerts.map((item) => (
                            <AlertCard
                                key={`saved-${item.id}`}
                                alert={item}
                                onViewDetails={() => onSelectAlert(item.id)}
                                isSaved={isSaved(item.id)}
                                isPending={isPending(item.id)}
                                onToggleSave={() => onToggleSave(item.id)}
                            />
                        ))}

                        {activeMissingIds.map((id) => (
                            <div
                                key={`missing-${id}`}
                                id={`gta-alerts-missing-alert-${id}`}
                                className="panel-shadow flex items-center justify-between border-4 border-black bg-panel-light p-4 opacity-60 grayscale"
                            >
                                <div className="flex items-center gap-3">
                                    <Icon
                                        name="report_off"
                                        className="text-2xl text-text-secondary"
                                    />
                                    <div>
                                        <h4 className="font-black text-black uppercase">
                                            Alert Unavailable
                                        </h4>
                                        <p className="text-xs font-bold text-text-secondary">
                                            {id} • No longer active or not in
                                            current feed
                                        </p>
                                    </div>
                                </div>
                                <button
                                    onClick={() => onToggleSave(id)}
                                    disabled={isPending(id)}
                                    className="border-2 border-black bg-white p-2 text-black transition-all hover:bg-black hover:text-primary"
                                    aria-label="Remove unavailable alert"
                                >
                                    <Icon
                                        name={isPending(id) ? 'sync' : 'delete'}
                                        className={
                                            isPending(id) ? 'animate-spin' : ''
                                        }
                                    />
                                </button>
                            </div>
                        ))}
                    </>
                )}
            </div>
        </section>
    );
};
