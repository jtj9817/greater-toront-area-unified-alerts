import React, { useMemo, useState } from 'react';
import { formatTimestampEST } from '@/lib/utils';
import {
    mapDomainAlertToPresentation,
    type DomainAlert,
} from '../domain/alerts';
import { Icon } from './Icon';

interface AlertTableViewProps {
    items: DomainAlert[];
    onSelectAlert: (id: string) => void;
    savedIds: Set<string>;
}

const severityStyles: Record<'high' | 'medium' | 'low', string> = {
    high: 'border border-black bg-critical px-3 py-1 text-[10px] text-white',
    medium: 'border border-black bg-warning px-3 py-1 text-[10px] text-black',
    low: 'border border-black bg-panel-light px-3 py-1 text-[10px] text-black',
};

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

function formatSeverityLabel(severity: 'high' | 'medium' | 'low'): string {
    if (severity === 'high') return 'Critical Severity';
    if (severity === 'medium') return 'Medium Priority';
    return 'Low Priority';
}

export const AlertTableView: React.FC<AlertTableViewProps> = ({
    items,
    onSelectAlert,
    savedIds,
}) => {
    const [expandedRowId, setExpandedRowId] = useState<string | null>(null);
    const toggleExpandedRow = (rowId: string) => {
        setExpandedRowId((current) => (current === rowId ? null : rowId));
    };

    const rows = useMemo(
        () =>
            items.map((alert) => ({
                alert,
                presentation: mapDomainAlertToPresentation(alert),
            })),
        [items],
    );

    return (
        <div id="gta-alerts-alert-table-wrap" className="panel-shadow w-full overflow-x-auto border-4 border-black">
            <table id="gta-alerts-alert-table" className="incident-table w-full min-w-[780px] border-collapse">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Incident Type</th>
                        <th>Location</th>
                        <th>Status</th>
                        <th>Severity</th>
                        <th>Source</th>
                        <th className="w-10">
                            <span className="sr-only">Expand</span>
                        </th>
                    </tr>
                </thead>
                <tbody className="bg-background-dark">
                    {rows.map(({ alert, presentation }) => {
                        const isExpanded = expandedRowId === presentation.id;
                        const isActive = alert.isActive;
                        const sourceLabel = getSourceLabel(alert);

                        return (
                            <React.Fragment key={presentation.id}>
                                <tr
                                    id={`gta-alerts-alert-table-row-${presentation.id}`}
                                    onClick={() =>
                                        toggleExpandedRow(presentation.id)
                                    }
                                    className={`expandable-row ${isExpanded ? 'active-row' : ''} ${!isActive ? 'opacity-80 grayscale-[0.35]' : ''}`}
                                >
                                    <td className="font-black">
                                        {formatTimestampEST(
                                            presentation.timestamp,
                                        )}
                                    </td>
                                    <td className="tracking-tight uppercase">
                                        {presentation.title}
                                    </td>
                                    <td className="underline decoration-primary decoration-2">
                                        {presentation.location}
                                    </td>
                                    <td>
                                        <span
                                            className={`px-2 py-1 text-[10px] font-black uppercase ${
                                                isActive
                                                    ? 'bg-black text-primary'
                                                    : 'bg-[#333333] text-text-secondary'
                                            }`}
                                        >
                                            {isActive ? 'Active' : 'Cleared'}
                                        </span>
                                    </td>
                                    <td>
                                        <span
                                            className={`font-black uppercase ${severityStyles[presentation.severity]}`}
                                        >
                                            {formatSeverityLabel(
                                                presentation.severity,
                                            )}
                                        </span>
                                    </td>
                                    <td className="text-xs tracking-wide uppercase">
                                        {sourceLabel}
                                        {savedIds.has(presentation.id) && (
                                            <span className="ml-2 bg-primary px-2 py-1 text-[10px] font-black text-black uppercase">
                                                Saved
                                            </span>
                                        )}
                                    </td>
                                    <td>
                                        <button
                                            id={`gta-alerts-alert-table-row-${presentation.id}-expand-btn`}
                                            type="button"
                                            onClick={(event) => {
                                                event.stopPropagation();
                                                toggleExpandedRow(
                                                    presentation.id,
                                                );
                                            }}
                                            aria-label={`${isExpanded ? 'Collapse' : 'Expand'} summary for ${presentation.title}`}
                                            className="flex h-8 w-8 items-center justify-center transition-colors hover:bg-black hover:text-primary"
                                        >
                                            <Icon
                                                name={
                                                    isExpanded
                                                        ? 'expand_less'
                                                        : 'expand_more'
                                                }
                                            />
                                        </button>
                                    </td>
                                </tr>
                                {isExpanded && (
                                    <tr className="bg-panel-light text-black">
                                        <td
                                            colSpan={7}
                                            className="border-b-4 border-black p-0"
                                        >
                                            <div
                                                className={`m-4 border-l-[12px] bg-panel-light p-6 ${presentation.severity === 'high' ? 'border-critical' : 'border-warning'}`}
                                            >
                                                <p className="mb-3 text-xs font-black tracking-widest text-critical uppercase">
                                                    Incident Summary
                                                </p>
                                                <p className="text-base leading-relaxed font-bold">
                                                    {presentation.description}
                                                </p>
                                                <div className="mt-4 flex flex-wrap gap-3 text-[10px] font-black uppercase">
                                                    <span className="border-2 border-black bg-panel-light px-3 py-1">
                                                        Event #
                                                        {
                                                            presentation
                                                                .metadata
                                                                ?.eventNum
                                                        }
                                                    </span>
                                                    {presentation.metadata
                                                        ?.unitsDispatched && (
                                                        <span className="flex items-center gap-1 border-2 border-black bg-panel-light px-3 py-1">
                                                            <Icon
                                                                name="fire_truck"
                                                                className="text-sm"
                                                            />
                                                            {
                                                                presentation
                                                                    .metadata
                                                                    .unitsDispatched
                                                            }
                                                        </span>
                                                    )}
                                                    <button
                                                        id={`gta-alerts-alert-table-row-${presentation.id}-details-btn`}
                                                        type="button"
                                                        onClick={(event) => {
                                                            event.stopPropagation();
                                                            onSelectAlert(
                                                                presentation.id,
                                                            );
                                                        }}
                                                        className="border-2 border-black bg-primary px-3 py-1 text-black transition-colors hover:bg-black hover:text-primary"
                                                    >
                                                        View Details
                                                    </button>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                )}
                            </React.Fragment>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
};
