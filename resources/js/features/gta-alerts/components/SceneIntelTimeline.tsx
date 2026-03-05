import React, { useMemo } from 'react';
import { formatTimestampEST, cn } from '@/lib/utils';
import type {
    SceneIntelItem,
    SceneIntelType,
} from '../domain/alerts/fire/scene-intel';
import { useSceneIntel } from '../hooks/useSceneIntel';
import { Icon } from './Icon';

interface SceneIntelTimelineProps {
    eventNum: string;
    initialItems?: SceneIntelItem[];
    className?: string;
}

const FALLBACK_STYLE = { text: 'text-gray-400', bg: 'bg-gray-400/10' };

const TYPE_STYLES: Record<SceneIntelType, { text: string; bg: string }> = {
    milestone: { text: 'text-purple-400', bg: 'bg-purple-400/10' },
    resource_status: { text: 'text-blue-400', bg: 'bg-blue-400/10' },
    alarm_change: { text: 'text-red-400', bg: 'bg-red-400/10' },
    phase_change: { text: 'text-green-400', bg: 'bg-green-400/10' },
    manual_note: { text: 'text-yellow-400', bg: 'bg-yellow-400/10' },
};

export const SceneIntelTimeline: React.FC<SceneIntelTimelineProps> = ({
    eventNum,
    initialItems = [],
    className,
}) => {
    const { items, loading, error, refresh } = useSceneIntel(
        eventNum,
        initialItems,
    );

    const getStyles = (type: SceneIntelType) =>
        TYPE_STYLES[type] ?? FALLBACK_STYLE;

    // Sort items by timestamp descending for display (newest first)
    const sortedItems = useMemo(() => {
        return [...items].reverse();
    }, [items]);

    return (
        <div
            id={`gta-alerts-scene-intel-timeline-${eventNum || 'unknown'}`}
            className={cn(
                'rounded-2xl border border-white/5 bg-surface-dark p-6',
                className,
            )}
        >
            <div className="mb-4 flex items-center justify-between">
                <h4 id={`gta-alerts-scene-intel-title-${eventNum || 'unknown'}`} className="flex items-center gap-2 text-xs font-bold tracking-wider text-primary uppercase">
                    <Icon name="list_alt" className="text-sm" /> Scene Intel
                </h4>
                {loading && (
                    <span className="flex animate-pulse items-center gap-1 text-[10px] text-primary">
                        <Icon name="sync" className="animate-spin text-xs" />{' '}
                        Live
                    </span>
                )}
            </div>

            {error && items.length === 0 && (
                <div className="flex flex-col items-center justify-center py-8 text-center">
                    <Icon
                        name="error_outline"
                        className="mb-2 text-2xl text-red-400"
                    />
                    <p className="text-sm text-red-200">
                        Unable to load updates
                    </p>
                    <button
                        id={`gta-alerts-scene-intel-retry-btn-${eventNum || 'unknown'}`}
                        onClick={() => refresh()}
                        className="mt-2 text-xs text-white/50 underline decoration-dashed hover:text-white"
                    >
                        Try Again
                    </button>
                </div>
            )}

            {!error && items.length === 0 && !loading && (
                <div className="flex flex-col items-center justify-center py-8 text-center text-white/30">
                    <Icon name="playlist_remove" className="mb-2 text-2xl" />
                    <p className="text-xs">No updates reported yet</p>
                </div>
            )}

            <div className="relative space-y-4">
                {/* Vertical line */}
                {items.length > 0 && (
                    <div className="absolute top-2 bottom-2 left-[11px] w-[1px] bg-white/5" />
                )}

                {sortedItems.map((item) => {
                    const styles = getStyles(item.type);
                    return (
                        <div key={item.id} className="group relative pl-8">
                            {/* Icon Dot */}
                            <div
                                className={cn(
                                    'absolute top-0.5 left-0 flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-surface-dark transition-colors duration-300',
                                    styles.bg,
                                    styles.text,
                                )}
                            >
                                <Icon
                                    name={item.icon || 'circle'}
                                    className="text-[12px]"
                                    fill
                                />
                            </div>

                            <div className="flex-1">
                                <p className="text-sm leading-snug text-white/90">
                                    {item.content}
                                </p>
                                <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <span
                                        className={cn(
                                            'text-[10px] font-bold tracking-wider uppercase',
                                            styles.text,
                                        )}
                                    >
                                        {item.type_label}
                                    </span>
                                    <span className="text-[10px] text-white/20">
                                        •
                                    </span>
                                    <span className="font-mono text-[10px] text-white/40">
                                        {formatTimestampEST(item.timestamp)}
                                    </span>
                                </div>
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
};
