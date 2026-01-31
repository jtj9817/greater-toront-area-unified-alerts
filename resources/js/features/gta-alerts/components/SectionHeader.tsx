import React from 'react';
import { Icon } from './Icon';

interface SectionHeaderProps {
  title: string;
  iconName: string;
  count: number;
}

export const SectionHeader: React.FC<SectionHeaderProps> = ({ title, iconName, count }) => {
  return (
    <div className="sticky top-0 z-40 glass-header px-4 py-3 border-b border-white/5 flex items-center justify-between group">
      <h3 className="text-primary text-sm font-bold uppercase tracking-wider flex items-center gap-2">
        <Icon name={iconName} className="text-lg" />
        {title}
      </h3>
      <span className="text-xs font-medium bg-primary/20 text-primary px-2 py-0.5 rounded-full">
        {count} Active
      </span>
    </div>
  );
};