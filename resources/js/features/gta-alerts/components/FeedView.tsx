import React, { useState, useMemo } from 'react';
import { AlertCard } from './AlertCard';
import { Icon } from './Icon';
import { AlertService, AlertFilterOptions } from '../services/AlertService';

interface FeedViewProps {
  searchQuery: string;
  onSelectAlert: (id: string) => void;
}

export const FeedView: React.FC<FeedViewProps> = ({ searchQuery, onSelectAlert }) => {
  // State for Filters
  const [activeCategory, setActiveCategory] = useState<string>('all');
  const [timeFilter, setTimeFilter] = useState<number | null>(null);
  const [dateFilter, setDateFilter] = useState<'today' | 'yesterday' | 'all'>('today');

  // Compute filtered items using the Service
  const filteredItems = useMemo(() => {
    const options: AlertFilterOptions = {
        query: searchQuery,
        category: activeCategory,
        timeLimit: timeFilter,
        dateScope: dateFilter
    };
    return AlertService.search(options);
  }, [searchQuery, activeCategory, timeFilter, dateFilter]);

  // Get Set of Saved IDs for efficient lookup
  const savedIds = useMemo(() => new Set(AlertService.getSavedItems().map(i => i.id)), []);

  // Handler for Reset
  const handleReset = () => {
    setActiveCategory('all');
    setTimeFilter(null);
    setDateFilter('today');
  };

  const categories = [
    { id: 'all', label: 'All Alerts', icon: 'grid_view' },
    { id: 'fire', label: 'Fire', icon: 'local_fire_department' },
    { id: 'police', label: 'Police', icon: 'local_police' },
    { id: 'hazard', label: 'Hazard', icon: 'warning' },
    { id: 'transit', label: 'Transit', icon: 'train' },
  ];

  const timeOptions = [
      { label: 'Any time', value: null },
      { label: 'Last 30m', value: 30 },
      { label: 'Last 1h', value: 60 },
      { label: 'Last 3h', value: 180 },
      { label: 'Last 6h', value: 360 },
      { label: 'Last 12h', value: 720 },
  ];

  return (
    <div className="flex flex-col h-full">
      {/* Sticky Header: Filters */}
      <div className="sticky top-0 z-30 bg-background-dark/95 backdrop-blur-md border-b border-white/5 shadow-lg">
        {/* Row 1: Categories */}
        <div className="py-3 px-4 md:px-6 border-b border-white/5">
            <div className="flex gap-2 overflow-x-auto no-scrollbar pb-1 mask-linear-fade justify-start w-full">
            {categories.map(cat => (
                <button
                key={cat.id}
                onClick={() => setActiveCategory(cat.id === activeCategory && activeCategory !== 'all' ? 'all' : cat.id)}
                className={`flex items-center gap-2 px-3 py-1.5 md:px-4 md:py-2 rounded-full text-xs font-medium transition-all whitespace-nowrap border ${
                    activeCategory === cat.id
                    ? 'bg-primary border-primary text-white shadow-lg shadow-primary/20'
                    : 'bg-surface-dark border-white/5 text-text-secondary hover:border-white/20 hover:text-white hover:bg-white/5'
                }`}
                >
                <Icon name={cat.icon} className="text-lg" />
                {cat.label}
                </button>
            ))}
            </div>
        </div>

        {/* Row 2: Date & Time Selectors */}
        <div className="py-2 px-4 md:px-6 flex flex-wrap gap-3 items-center bg-surface-dark/30">
            {/* Date Selector */}
            <div className="relative group">
                <div className="absolute inset-y-0 left-2 flex items-center pointer-events-none text-text-secondary">
                    <Icon name="calendar_today" className="text-sm" />
                </div>
                <select 
                    value={dateFilter}
                    onChange={(e) => setDateFilter(e.target.value as any)}
                    className="pl-8 pr-8 py-1.5 bg-surface-dark border border-white/10 rounded-lg text-xs text-white focus:ring-1 focus:ring-primary focus:border-primary outline-none appearance-none hover:border-white/20 cursor-pointer transition-colors w-32"
                >
                    <option value="today">Today</option>
                    <option value="yesterday">Yesterday</option>
                    <option value="all">All Dates</option>
                </select>
                <div className="absolute inset-y-0 right-2 flex items-center pointer-events-none text-text-secondary">
                    <Icon name="expand_more" className="text-sm" />
                </div>
            </div>

            {/* Time Selector */}
            <div className="relative group">
                <div className="absolute inset-y-0 left-2 flex items-center pointer-events-none text-text-secondary">
                    <Icon name="schedule" className="text-sm" />
                </div>
                <select 
                    value={timeFilter === null ? 'null' : timeFilter}
                    onChange={(e) => setTimeFilter(e.target.value === 'null' ? null : Number(e.target.value))}
                    className="pl-8 pr-8 py-1.5 bg-surface-dark border border-white/10 rounded-lg text-xs text-white focus:ring-1 focus:ring-primary focus:border-primary outline-none appearance-none hover:border-white/20 cursor-pointer transition-colors w-36"
                >
                    {timeOptions.map((opt) => (
                        <option key={String(opt.value)} value={String(opt.value)}>
                            {opt.label}
                        </option>
                    ))}
                </select>
                <div className="absolute inset-y-0 right-2 flex items-center pointer-events-none text-text-secondary">
                    <Icon name="expand_more" className="text-sm" />
                </div>
            </div>
            
            {/* Reset Button (Only shows if filters are active) */}
            {(timeFilter !== null || activeCategory !== 'all' || dateFilter !== 'today') && (
                <button 
                    onClick={handleReset}
                    className="ml-auto text-xs font-medium text-red-400 hover:text-red-300 flex items-center gap-1 transition-colors px-2 py-1 rounded hover:bg-red-500/10"
                >
                    <Icon name="restart_alt" className="text-sm" />
                    Reset
                </button>
            )}
        </div>
      </div>

      {/* List Container */}
      <div className="p-4 md:p-6 flex-1 overflow-y-auto">
        <div className="flex flex-col gap-4 md:gap-5 w-full max-w-3xl mx-auto">
          {filteredItems.map(item => (
            <AlertCard 
              key={item.id} 
              item={item} 
              onViewDetails={() => onSelectAlert(item.id)}
              isSaved={savedIds.has(item.id)}
            />
          ))}
        </div>

        {/* Empty State */}
        {filteredItems.length === 0 && (
          <div className="flex flex-col items-center justify-center py-20 text-center animate-in fade-in zoom-in duration-300">
             <div className="w-16 h-16 rounded-full bg-white/5 flex items-center justify-center mb-4">
                <Icon name="filter_list_off" className="text-3xl text-text-secondary opacity-30" />
             </div>
             <p className="text-lg font-bold text-white mb-2">No alerts match your filters</p>
             <p className="text-text-secondary text-sm max-w-xs mx-auto mb-6">
                Try adjusting the time range or date selection to see more results.
             </p>
             <button 
                onClick={handleReset}
                className="px-6 py-2 bg-primary hover:bg-primary/90 text-white text-sm font-bold rounded-lg transition-colors shadow-lg shadow-primary/20"
              >
                Reset All Filters
              </button>
          </div>
        )}
      </div>
    </div>
  );
};