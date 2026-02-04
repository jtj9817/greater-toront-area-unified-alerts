import React, { useMemo } from 'react';
import type { AlertItem } from '../types';
import { AlertCard } from './AlertCard';
import { Icon } from './Icon';

interface SavedViewProps {
    onSelectAlert: (id: string) => void;
    allAlerts?: AlertItem[];
}

export const SavedView: React.FC<SavedViewProps> = ({ onSelectAlert }) => {
    // Use the service to get saved items (Mocked for now as empty)
    const savedItems = useMemo<AlertItem[]>(() => [], []);

    return (
        <div className="p-4 md:p-6">
            <div className="mx-auto mb-8 max-w-3xl">
                <h2 className="mb-2 flex items-center gap-3 text-2xl font-bold text-white">
                    <Icon name="bookmark" className="text-primary" />
                    Saved Alerts
                </h2>
                <p className="text-sm text-text-secondary">
                    Review incidents you've flagged for monitoring.
                </p>
            </div>

            <div className="grid w-full grid-cols-1 gap-4 md:grid-cols-2 md:gap-6">
                {savedItems.map((item) => (
                    <AlertCard
                        key={`saved-${item.id}`}
                        item={item}
                        onViewDetails={() => onSelectAlert(item.id)}
                        isSaved={true}
                    />
                ))}

                <button className="group flex h-24 flex-col items-center justify-center rounded-xl border-2 border-dashed border-white/10 text-text-secondary transition-all hover:border-primary/30 hover:bg-white/5">
                    <Icon
                        name="add"
                        className="mb-1 text-2xl opacity-50 transition-all group-hover:text-primary group-hover:opacity-100"
                    />
                    <span className="text-xs font-bold tracking-widest uppercase">
                        Create Watchlist
                    </span>
                </button>
            </div>
        </div>
    );
};
