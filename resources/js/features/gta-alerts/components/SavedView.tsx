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
      <div className="mb-8 max-w-3xl mx-auto">
        <h2 className="text-2xl font-bold text-white mb-2 flex items-center gap-3">
          <Icon name="bookmark" className="text-primary" />
          Saved Alerts
        </h2>
        <p className="text-text-secondary text-sm">Review incidents you've flagged for monitoring.</p>
      </div>

      <div className="flex flex-col gap-4 md:gap-6 w-full max-w-3xl mx-auto">
        {savedItems.map((item) => (
          <AlertCard 
            key={`saved-${item.id}`} 
            item={item} 
            onViewDetails={() => onSelectAlert(item.id)} 
            isSaved={true}
          />
        ))}
        
        <button className="h-24 border-2 border-dashed border-white/10 rounded-xl flex flex-col items-center justify-center text-text-secondary hover:border-primary/30 hover:bg-white/5 transition-all group">
           <Icon name="add" className="text-2xl mb-1 opacity-50 group-hover:opacity-100 group-hover:text-primary transition-all" />
           <span className="text-xs font-bold uppercase tracking-widest">Create Watchlist</span>
        </button>
      </div>
    </div>
  );
};