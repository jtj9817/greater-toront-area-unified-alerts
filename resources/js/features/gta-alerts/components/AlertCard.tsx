import React from 'react';
import type { AlertItem } from '../types';
import { Icon } from './Icon';

interface AlertCardProps {
  item: AlertItem;
  onViewDetails?: () => void;
  isSaved?: boolean;
}

export const AlertCard: React.FC<AlertCardProps> = ({ item, onViewDetails, isSaved = false }) => {
  return (
    <article 
      onClick={onViewDetails}
      className={`
        h-full bg-surface-dark rounded-lg p-4 relative overflow-hidden group transition-all cursor-pointer hover:-translate-y-0.5
        ${isSaved 
          ? 'border border-primary/50 shadow-[0_0_15px_rgba(238,113,17,0.15)]' 
          : 'border border-white/5 shadow-lg shadow-black/20 hover:border-primary/40'
        }
      `}
    >
      <div className={`absolute left-0 top-0 bottom-0 w-1 ${item.accentColor} ${isSaved ? 'opacity-100' : 'opacity-80 group-hover:opacity-100'} transition-opacity`}></div>
      
      {/* Saved Background Highlight */}
      {isSaved && (
        <div className="absolute inset-0 bg-gradient-to-br from-primary/10 via-transparent to-transparent pointer-events-none" />
      )}
      
      <div className="pl-3 h-full flex flex-col relative z-10">
        <div className="flex justify-between items-start mb-2">
          <div className="pr-8">
             <div className="flex items-center gap-2 mb-1">
                <span className={`w-2 h-2 rounded-full ${item.severity === 'high' ? 'animate-pulse bg-red-500' : 'bg-gray-500'}`}></span>
                <span className="text-primary text-[10px] font-bold uppercase tracking-wider">{item.type}</span>
                {isSaved && (
                  <span className="bg-primary text-white text-[9px] font-bold px-1.5 py-0.5 rounded ml-1 animate-in fade-in slide-in-from-left-2">SAVED</span>
                )}
             </div>
             <h4 className="text-white text-lg font-medium leading-tight">
                {item.title}
             </h4>
          </div>
          
          <div className="flex gap-2">
            {isSaved ? (
                <span className="text-primary bg-primary/10 p-2 rounded-lg animate-in zoom-in duration-300 border border-primary/20">
                    <Icon name="bookmark" fill={true} />
                </span>
            ) : (
                <span className="text-white/20 group-hover:text-primary transition-colors bg-white/5 p-2 rounded-lg">
                    <Icon name={item.iconName} />
                </span>
            )}
          </div>
        </div>
        
        <p className="text-text-secondary text-xs font-medium mb-3 flex items-center gap-1.5 opacity-80">
          <Icon name="location_on" className="text-[14px]" />
          {item.location}
          <span className="w-1 h-1 rounded-full bg-white/20 mx-1"></span>
          <Icon name="schedule" className="text-[14px]" />
          {item.timeAgo}
        </p>
        
        <p className="text-gray-300 text-sm font-normal leading-relaxed line-clamp-3 mb-4 flex-1">
          {item.description}
        </p>
        
        <div className="mt-auto pt-3 border-t border-white/5 flex justify-between items-center">
            <span className="text-xs text-primary font-medium group-hover:underline">View Details</span>
            <Icon name="arrow_forward" className="text-sm text-primary -ml-2 opacity-0 group-hover:opacity-100 group-hover:translate-x-2 transition-all" />
        </div>
      </div>
    </article>
  );
};