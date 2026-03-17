import React, { useMemo } from 'react';
import { formatTimestampEST } from '@/lib/utils';
import {
    mapDomainAlertToPresentation,
    type DomainAlert,
} from '../domain/alerts';
import { Icon } from './Icon';

interface AlertCardProps {
    alert: DomainAlert;
    onViewDetails?: () => void;
    isSaved?: boolean;
    isPending?: boolean;
    onToggleSave?: () => void;
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
    isPending = false,
    onToggleSave,
}) => {
    const item = useMemo(() => mapDomainAlertToPresentation(alert), [alert]);
    const sourceLabel = getSourceLabel(alert);
    const isActive = alert.isActive;
    const eventReference = item.metadata?.eventNum ?? alert.externalId;
    const hasLocation = !!item.location && item.location !== 'Unknown location';
    const truncatedEventRef =
        eventReference.length > 20
            ? `${eventReference.slice(0, 20)}…`
            : eventReference;
    const severityLabel =
        item.severity === 'high'
            ? 'Critical Severity'
            : item.severity === 'medium'
              ? 'Medium Priority'
              : 'Low Priority';
    const severityClasses =
        item.severity === 'high'
            ? 'border-2 border-black bg-critical text-white'
            : item.severity === 'medium'
              ? 'border-2 border-black bg-warning text-black'
              : 'border-2 border-black bg-panel-light text-black';
    const summaryBorderClass =
        item.severity === 'high' ? 'border-critical' : 'border-warning';
    const summaryLabelColor =
        item.severity === 'high'
            ? 'text-critical'
            : item.severity === 'medium'
              ? 'text-amber-600'
              : 'text-text-secondary';

    return (
        <article
            id={`gta-alerts-alert-card-${item.id}`}
            onClick={onViewDetails}
            className={`group panel-shadow cursor-pointer border-4 border-black bg-panel-light p-5 text-black transition-all duration-150 hover:-translate-x-[1px] hover:-translate-y-[1px] hover:shadow-[9px_9px_0_#000] ${isActive ? '' : 'opacity-80 grayscale'} ${isSaved ? 'ring-2 ring-primary' : ''}`}
        >
            <div
                id={`gta-alerts-alert-card-${item.id}-inner-wrap`}
                className="flex flex-col gap-5 md:flex-row"
            >
                <div
                    id={`gta-alerts-alert-card-${item.id}-sidebar`}
                    className="flex items-center justify-between gap-3 border-b-2 border-black pb-3 md:w-28 md:flex-col md:items-center md:justify-start md:border-r-2 md:border-b-0 md:pr-4 md:pb-0"
                >
                    <span
                        id={`gta-alerts-alert-card-${item.id}-timestamp`}
                        className={`text-sm font-black whitespace-nowrap uppercase ${isActive ? 'text-black' : 'text-text-secondary'}`}
                    >
                        {formatTimestampEST(item.timestamp)}
                    </span>
                    <div
                        id={`gta-alerts-alert-card-${item.id}-sidebar-divider`}
                        className="h-1 w-full bg-black md:my-2"
                    ></div>
                    <span
                        id={`gta-alerts-alert-card-${item.id}-status-badge`}
                        className={`px-2 py-1 text-[10px] font-black tracking-widest uppercase ${isActive ? 'bg-black text-primary' : 'bg-[#333333] text-text-secondary'}`}
                    >
                        {isActive ? 'Active' : 'Cleared'}
                    </span>
                </div>

                <div
                    id={`gta-alerts-alert-card-${item.id}-main-content`}
                    className="flex-1"
                >
                    <div
                        id={`gta-alerts-alert-card-${item.id}-header-row`}
                        className="mb-4 flex flex-wrap items-center justify-between gap-3"
                    >
                        <div
                            id={`gta-alerts-alert-card-${item.id}-title-wrap`}
                            className="flex flex-wrap items-center gap-2"
                        >
                            <h4
                                id={`gta-alerts-alert-card-${item.id}-title`}
                                className="text-xl leading-tight font-black tracking-tight uppercase md:text-2xl"
                            >
                                {item.title}
                            </h4>
                            <button
                                id={`gta-alerts-alert-card-${item.id}-save-btn`}
                                type="button"
                                onClick={(e) => {
                                    e.stopPropagation();
                                    onToggleSave?.();
                                }}
                                disabled={isPending}
                                className={`flex h-10 w-10 items-center justify-center border-2 border-black bg-white transition-all hover:bg-black hover:text-primary active:translate-x-0.5 active:translate-y-0.5 active:shadow-none ${isSaved ? 'bg-primary text-black' : 'text-black shadow-[3px_3px_0_#000]'} ${isPending ? 'cursor-wait opacity-70' : ''}`}
                                aria-label={
                                    isSaved ? 'Remove alert' : 'Save alert'
                                }
                            >
                                <Icon
                                    name={
                                        isPending
                                            ? 'sync'
                                            : isSaved
                                              ? 'bookmark'
                                              : 'bookmark_border'
                                    }
                                    className={
                                        isPending ? 'animate-spin' : 'text-xl'
                                    }
                                    fill={isSaved}
                                />
                            </button>
                        </div>
                        <div
                            id={`gta-alerts-alert-card-${item.id}-meta-wrap`}
                            className="flex items-center gap-2 text-[10px] font-black tracking-wider uppercase"
                        >
                            <span
                                id={`gta-alerts-alert-card-${item.id}-source-label`}
                                className="border-2 border-black bg-panel-light px-2 py-1"
                            >
                                {sourceLabel}
                            </span>
                            <span
                                id={`gta-alerts-alert-card-${item.id}-severity-label`}
                                className={`px-3 py-1 text-[10px] font-black tracking-wider uppercase ${severityClasses}`}
                            >
                                {severityLabel}
                            </span>
                        </div>
                    </div>

                    <p
                        id={`gta-alerts-alert-card-${item.id}-location-row`}
                        className="mb-4 flex flex-wrap items-center gap-2 text-sm font-bold"
                    >
                        {hasLocation && (
                            <>
                                <Icon
                                    id={`gta-alerts-alert-card-${item.id}-location-icon`}
                                    name="location_on"
                                    className={`text-base ${item.iconColor}`}
                                />
                                <span
                                    id={`gta-alerts-alert-card-${item.id}-location-text`}
                                    className="underline decoration-primary decoration-2"
                                >
                                    {item.location}
                                </span>
                                <span
                                    id={`gta-alerts-alert-card-${item.id}-location-divider`}
                                    className="text-black/60"
                                >
                                    |
                                </span>
                            </>
                        )}
                        <Icon
                            id={`gta-alerts-alert-card-${item.id}-time-icon`}
                            name="schedule"
                            className={`text-base ${item.iconColor}`}
                        />
                        <span id={`gta-alerts-alert-card-${item.id}-time-ago`}>
                            {item.timeAgo}
                        </span>
                    </p>

                    <div
                        id={`gta-alerts-alert-card-${item.id}-summary-box`}
                        className={`border-l-[12px] bg-panel-light p-4 ${summaryBorderClass}`}
                    >
                        <p
                            id={`gta-alerts-alert-card-${item.id}-summary-label`}
                            className={`mb-2 text-[11px] font-black tracking-widest uppercase ${summaryLabelColor}`}
                        >
                            Incident Summary
                        </p>
                        <p
                            id={`gta-alerts-alert-card-${item.id}-summary-text`}
                            className="text-sm leading-relaxed font-bold"
                        >
                            {item.description}
                        </p>
                    </div>

                    <div
                        id={`gta-alerts-alert-card-${item.id}-footer-actions`}
                        className="mt-4 flex flex-wrap items-center gap-3 text-[10px] font-black tracking-wider uppercase"
                    >
                        <span
                            id={`gta-alerts-alert-card-${item.id}-event-ref`}
                            className="flex items-center gap-1 border-2 border-black bg-panel-light px-2 py-1"
                        >
                            <Icon
                                id={`gta-alerts-alert-card-${item.id}-event-icon`}
                                name={item.iconName}
                                className={item.iconColor}
                            />
                            Event #{truncatedEventRef}
                        </span>
                        {item.metadata?.unitsDispatched && (
                            <span
                                id={`gta-alerts-alert-card-${item.id}-units-dispatched`}
                                className="flex items-center gap-1 border-2 border-black bg-panel-light px-2 py-1"
                            >
                                <Icon
                                    id={`gta-alerts-alert-card-${item.id}-units-icon`}
                                    name="fire_truck"
                                    className="text-sm"
                                />
                                {item.metadata.unitsDispatched}
                            </span>
                        )}
                        <span
                            id={`gta-alerts-alert-card-${item.id}-view-details-link`}
                            className="ml-auto flex items-center gap-1 text-primary"
                        >
                            View Details
                            <Icon
                                id={`gta-alerts-alert-card-${item.id}-view-details-icon`}
                                name="arrow_forward"
                                className="text-sm transition-transform group-hover:translate-x-1"
                            />
                        </span>
                    </div>
                </div>
            </div>
        </article>
    );
};
