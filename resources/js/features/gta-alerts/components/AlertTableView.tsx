import React, { useMemo } from 'react';
import { formatTimestampEST } from '@/lib/utils';
import { mapDomainAlertToPresentation, type DomainAlert } from '../domain/alerts';
import { Icon } from './Icon';

interface AlertTableViewProps {
    items: DomainAlert[];
    onSelectAlert: (id: string) => void;
    savedIds: Set<string>;
}

const severityStyles: Record<'high' | 'medium' | 'low', string> = {
    high: 'bg-[#e05560]/15 text-[#e05560] border-[#e05560]/20',
    medium: 'bg-[#f0b040]/15 text-[#f0b040] border-[#f0b040]/20',
    low: 'bg-white/10 text-[#8b95a5] border-white/10',
};

export const AlertTableView: React.FC<AlertTableViewProps> = ({
    items,
    onSelectAlert,
    savedIds,
}) => {
    const rows = useMemo(
        () => items.map((item) => mapDomainAlertToPresentation(item)),
        [items],
    );

    return (
        <div className="w-full overflow-x-auto rounded-lg border border-white/5">
            <table className="w-full min-w-[700px]">
                <thead>
                    <tr className="border-b border-white/10 bg-surface-dark">
                        <th className="px-4 py-3 text-left text-[10px] font-bold tracking-widest text-text-secondary uppercase">
                            Type
                        </th>
                        <th className="px-4 py-3 text-left text-[10px] font-bold tracking-widest text-text-secondary uppercase">
                            Severity
                        </th>
                        <th className="px-4 py-3 text-left text-[10px] font-bold tracking-widest text-text-secondary uppercase">
                            Title
                        </th>
                        <th className="px-4 py-3 text-left text-[10px] font-bold tracking-widest text-text-secondary uppercase">
                            Location
                        </th>
                        <th className="px-4 py-3 text-left text-[10px] font-bold tracking-widest text-text-secondary uppercase">
                            Time
                        </th>
                        <th className="w-10 px-2 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((item) => (
                        <tr
                            key={item.id}
                            onClick={() => onSelectAlert(item.id)}
                            className={`cursor-pointer border-b border-white/5 transition-colors hover:bg-white/5 ${
                                savedIds.has(item.id) ? 'bg-primary/5' : ''
                            }`}
                        >
                            <td className="px-4 py-3">
                                <div className="flex items-center gap-2">
                                    <span
                                        className={`h-2 w-2 rounded-full ${item.accentColor}`}
                                    />
                                    <span className="text-xs font-medium text-white capitalize">
                                        {item.type}
                                    </span>
                                </div>
                            </td>
                            <td className="px-4 py-3">
                                <span
                                    className={`inline-flex rounded-full border px-2 py-0.5 text-[10px] font-bold uppercase ${severityStyles[item.severity]}`}
                                >
                                    {item.severity}
                                </span>
                            </td>
                            <td className="max-w-[300px] truncate px-4 py-3 text-sm font-medium text-white">
                                {item.title}
                            </td>
                            <td className="max-w-[200px] truncate px-4 py-3 text-xs text-text-secondary">
                                {item.location}
                            </td>
                            <td className="px-4 py-3">
                                <div className="text-xs text-white">
                                    {formatTimestampEST(item.timestamp)}
                                </div>
                                <div className="text-[10px] text-text-secondary">
                                    {item.timeAgo}
                                </div>
                            </td>
                            <td className="px-2 py-3">
                                <Icon
                                    name="chevron_right"
                                    className="text-sm text-white/30"
                                />
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
};
