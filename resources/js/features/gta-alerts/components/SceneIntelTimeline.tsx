import React, { useMemo } from 'react';
import { useSceneIntel } from '../hooks/useSceneIntel';
import type { SceneIntelItem, SceneIntelType } from '../domain/alerts/fire/scene-intel';
import { Icon } from './Icon';
import { formatTimestampEST, cn } from '@/lib/utils';

interface SceneIntelTimelineProps {
    eventNum: string;
    initialItems?: SceneIntelItem[];
    className?: string;
}

export const SceneIntelTimeline: React.FC<SceneIntelTimelineProps> = ({
    eventNum,
    initialItems = [],
    className,
}) => {
    const { items, loading, error, refresh } = useSceneIntel(
        eventNum,
        initialItems,
    );

    const getIconColor = (type: SceneIntelType) => {
        switch (type) {
            case 'milestone':
                return 'text-purple-400';
            case 'resource_status':
                return 'text-blue-400';
            case 'alarm_change':
                return 'text-red-400';
            case 'phase_change':
                return 'text-green-400';
            case 'manual_note':
                return 'text-yellow-400';
            default:
                return 'text-gray-400';
        }
    };

    const getBgColor = (type: SceneIntelType) => {
        switch (type) {
            case 'milestone':
                return 'bg-purple-400/10';
            case 'resource_status':
                return 'bg-blue-400/10';
            case 'alarm_change':
                return 'bg-red-400/10';
            case 'phase_change':
                return 'bg-green-400/10';
            case 'manual_note':
                return 'bg-yellow-400/10';
            default:
                return 'bg-gray-400/10';
        }
    };

    // Sort items by timestamp descending for display (newest first)
    const sortedItems = useMemo(() => {
        return [...items].reverse();
    }, [items]);

    return (
        <div className={cn("rounded-2xl border border-white/5 bg-surface-dark p-6", className)}>
            <div className="flex items-center justify-between mb-4">
                <h4 className="flex items-center gap-2 text-xs font-bold text-primary uppercase tracking-wider">
                    <Icon name="list_alt" className="text-sm" /> Scene Intel
                </h4>
                {loading && (
                    <span className="flex items-center gap-1 text-[10px] text-primary animate-pulse">
                        <Icon name="sync" className="text-xs animate-spin" /> Live
                    </span>
                )}
            </div>

            {error && items.length === 0 && (
                <div className="flex flex-col items-center justify-center py-8 text-center">
                    <Icon name="error_outline" className="text-2xl text-red-400 mb-2" />
                    <p className="text-sm text-red-200">Unable to load updates</p>
                    <button 
                        onClick={() => refresh()}
                        className="mt-2 text-xs text-white/50 hover:text-white underline decoration-dashed"
                    >
                        Try Again
                    </button>
                </div>
            )}

            {!error && items.length === 0 && !loading && (
                <div className="flex flex-col items-center justify-center py-8 text-center text-white/30">
                    <Icon name="playlist_remove" className="text-2xl mb-2" />
                    <p className="text-xs">No updates reported yet</p>
                </div>
            )}

            <div className="space-y-4 relative">
                 {/* Vertical line */}
                {items.length > 0 && (
                    <div className="absolute left-[11px] top-2 bottom-2 w-[1px] bg-white/5" />
                )}

                {sortedItems.map((item) => (
                    <div key={item.id} className="relative pl-8 group">
                         {/* Icon Dot */}
                        <div className={cn(
                            "absolute left-0 top-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-surface-dark transition-colors duration-300",
                            getBgColor(item.type),
                            getIconColor(item.type)
                        )}>
                            <Icon name={item.icon || 'circle'} className="text-[12px]" fill />
                        </div>

                        <div className="flex-1">
                            <p className="text-sm text-white/90 leading-snug">
                                {item.content}
                            </p>
                            <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span className={cn(
                                    "text-[10px] font-bold uppercase tracking-wider",
                                    getIconColor(item.type)
                                )}>
                                    {item.type_label}
                                </span>
                                <span className="text-[10px] text-white/20">•</span>
                                <span className="text-[10px] text-white/40 font-mono">
                                    {formatTimestampEST(item.timestamp)}
                                </span>
                            </div>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
};
