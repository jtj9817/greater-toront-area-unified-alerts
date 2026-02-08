import React, { useMemo } from 'react';
import { formatTimestampEST } from '@/lib/utils';
import { mapDomainAlertToPresentation, type DomainAlert } from '../domain/alerts';
import { Icon } from './Icon';

interface AlertCardProps {
    alert: DomainAlert;
    onViewDetails?: () => void;
    isSaved?: boolean;
}

function getSourceLabel(alert: DomainAlert): string {
    switch (alert.kind) {
        case 'fire':
            return 'Toronto Fire';
        case 'police':
            return 'Toronto Police';
        case 'transit':
            return 'TTC';
        case 'go_transit':
            return 'GO Transit';
    }
}

export const AlertCard: React.FC<AlertCardProps> = ({
    alert,
    onViewDetails,
    isSaved = false,
}) => {
    const item = useMemo(() => mapDomainAlertToPresentation(alert), [alert]);
    const sourceLabel = getSourceLabel(alert);

    return (
        <article
            onClick={onViewDetails}
            className={`group relative h-full cursor-pointer overflow-hidden rounded-lg bg-surface-dark p-4 transition-all duration-200 ${
                isSaved
                    ? 'border border-primary/50 shadow-[0_0_15px_rgba(59,130,246,0.15)]'
                    : 'border border-white/5 shadow-lg shadow-black/20 hover:border-white/10 hover:shadow-[0_0_15px_rgba(59,130,246,0.06)]'
            } `}
        >
            <div
                className={`absolute top-0 bottom-0 left-0 w-1 ${item.accentColor} ${isSaved ? 'opacity-100' : 'opacity-80 group-hover:w-1.5 group-hover:opacity-100'} transition-all`}
            ></div>

            {/* Saved Background Highlight */}
            {isSaved && (
                <div className="pointer-events-none absolute inset-0 bg-gradient-to-br from-primary/10 via-transparent to-transparent" />
            )}

            <div className="relative z-10 flex h-full flex-col pl-3">
                <div className="mb-2 flex items-start justify-between">
                    <div className="pr-8">
                        <div className="mb-1 flex items-center gap-2">
                            <span
                                className={`h-2 w-2 rounded-full ${item.severity === 'high' ? 'animate-pulse bg-coral' : 'bg-gray-500'}`}
                            ></span>
                            <span className="text-[10px] font-bold tracking-wider text-primary uppercase">
                                {item.type}
                            </span>
                            <span className="text-[10px] font-semibold tracking-wide text-white/50 uppercase">
                                {sourceLabel}
                            </span>
                            {isSaved && (
                                <span className="ml-1 animate-in rounded bg-primary px-1.5 py-0.5 text-[9px] font-bold text-white fade-in slide-in-from-left-2">
                                    SAVED
                                </span>
                            )}
                        </div>
                        <h4 className="text-lg leading-tight font-medium text-white">
                            {item.title}
                        </h4>
                    </div>

                    <div className="flex gap-2">
                        {isSaved ? (
                            <span className="animate-in rounded-lg border border-primary/20 bg-primary/10 p-2 text-primary duration-300 zoom-in">
                                <Icon name="bookmark" fill={true} />
                            </span>
                        ) : (
                            <span
                                className={`rounded-lg bg-white/5 p-2 ${item.iconColor} opacity-70 transition-opacity group-hover:opacity-100`}
                            >
                                <Icon name={item.iconName} />
                            </span>
                        )}
                    </div>
                </div>

                <p className="mb-3 flex items-center gap-1.5 text-xs font-medium text-text-secondary opacity-80">
                    <Icon name="location_on" className="text-[14px]" />
                    {item.location}
                    <span className="mx-1 h-1 w-1 rounded-full bg-white/20"></span>
                    <Icon name="schedule" className="text-[14px]" />
                    {formatTimestampEST(item.timestamp)}
                    <span className="text-white/30">({item.timeAgo})</span>
                </p>

                <p className="mb-4 line-clamp-3 flex-1 text-sm leading-relaxed font-normal text-gray-300">
                    {item.description}
                </p>

                <div className="mt-auto flex items-center justify-between border-t border-white/5 pt-3">
                    <span className="text-xs font-medium text-primary group-hover:underline">
                        View Details
                    </span>
                    <Icon
                        name="arrow_forward"
                        className="-ml-2 text-sm text-primary opacity-0 transition-all group-hover:translate-x-2 group-hover:opacity-100"
                    />
                </div>
            </div>
        </article>
    );
};
