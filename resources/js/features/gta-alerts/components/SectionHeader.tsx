import React from 'react';
import { Icon } from './Icon';

interface SectionHeaderProps {
    title: string;
    iconName: string;
    count: number;
}

export const SectionHeader: React.FC<SectionHeaderProps> = ({
    title,
    iconName,
    count,
}) => {
    return (
        <div id={`gta-alerts-section-header-${iconName}`} className="glass-header group sticky top-0 z-40 flex items-center justify-between border-b border-white/5 px-4 py-3">
            <h3 id={`gta-alerts-section-header-${iconName}-title`} className="flex items-center gap-2 text-sm font-bold tracking-wider text-primary uppercase">
                <Icon name={iconName} className="text-lg" />
                {title}
            </h3>
            <span id={`gta-alerts-section-header-${iconName}-count`} className="rounded-full bg-primary/20 px-2 py-0.5 text-xs font-medium text-primary">
                {count} Active
            </span>
        </div>
    );
};
