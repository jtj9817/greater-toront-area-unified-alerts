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
    const isActive = alert.isActive;
    const eventReference = item.metadata?.eventNum ?? alert.externalId;
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

    return (
        <article
            onClick={onViewDetails}
            className={`group panel-shadow cursor-pointer border-4 border-black bg-panel-light p-5 text-black transition-all duration-150 hover:-translate-x-[1px] hover:-translate-y-[1px] hover:shadow-[9px_9px_0_#000] ${isActive ? '' : 'opacity-80 grayscale'} ${isSaved ? 'ring-2 ring-primary' : ''}`}
        >
            <div className="flex flex-col gap-5 md:flex-row">
                <div className="flex items-center justify-between gap-3 border-b-2 border-black pb-3 md:w-28 md:flex-col md:items-center md:justify-start md:border-r-2 md:border-b-0 md:pr-4 md:pb-0">
                    <span
                        className={`text-sm font-black uppercase ${isActive ? 'text-black' : 'text-text-secondary'}`}
                    >
                        {formatTimestampEST(item.timestamp)}
                    </span>
                    <div className="h-1 w-full bg-black md:my-2"></div>
                    <span
                        className={`px-2 py-1 text-[10px] font-black tracking-widest uppercase ${isActive ? 'bg-black text-primary' : 'bg-[#333333] text-text-secondary'}`}
                    >
                        {isActive ? 'Active' : 'Cleared'}
                    </span>
                </div>

                <div className="flex-1">
                    <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <div className="flex flex-wrap items-center gap-2">
                            <h4 className="text-xl leading-tight font-black tracking-tight uppercase md:text-2xl">
                                {item.title}
                            </h4>
                            <span
                                className={`px-3 py-1 text-[10px] font-black tracking-wider uppercase ${severityClasses}`}
                            >
                                {severityLabel}
                            </span>
                        </div>
                        <div className="flex items-center gap-2 text-[10px] font-black tracking-wider uppercase">
                            <span className="border-2 border-black bg-panel-light px-2 py-1">
                                {sourceLabel}
                            </span>
                            {isSaved && (
                                <span className="border-2 border-black bg-primary px-2 py-1 text-black">
                                    SAVED
                                </span>
                            )}
                        </div>
                    </div>

                    <p className="mb-4 flex flex-wrap items-center gap-2 text-sm font-bold">
                        <Icon
                            name="location_on"
                            className={`text-base ${item.iconColor}`}
                        />
                        <span className="underline decoration-primary decoration-2">
                            {item.location}
                        </span>
                        <span className="text-black/60">|</span>
                        <Icon
                            name="schedule"
                            className={`text-base ${item.iconColor}`}
                        />
                        <span>{item.timeAgo}</span>
                    </p>

                    <div
                        className={`border-l-[12px] bg-panel-light p-4 ${summaryBorderClass}`}
                    >
                        <p className="mb-2 text-[11px] font-black tracking-widest text-critical uppercase">
                            Incident Summary
                        </p>
                        <p className="text-sm leading-relaxed font-bold">
                            {item.description}
                        </p>
                    </div>

                    <div className="mt-4 flex flex-wrap items-center gap-3 text-[10px] font-black tracking-wider uppercase">
                        <span className="flex items-center gap-1 border-2 border-black bg-panel-light px-2 py-1">
                            <Icon
                                name={item.iconName}
                                className={item.iconColor}
                            />
                            Event #{eventReference}
                        </span>
                        {item.metadata?.unitsDispatched && (
                            <span className="flex items-center gap-1 border-2 border-black bg-panel-light px-2 py-1">
                                <Icon name="fire_truck" className="text-sm" />
                                {item.metadata.unitsDispatched}
                            </span>
                        )}
                        <span className="ml-auto flex items-center gap-1 text-primary">
                            View Details
                            <Icon
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
