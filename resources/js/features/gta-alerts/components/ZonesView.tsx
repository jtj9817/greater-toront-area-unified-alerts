import React from 'react';
import { Icon } from './Icon';

const ZONES = [
  { id: 1, name: 'Downtown Core', status: 'High Activity', color: 'text-red-500', bg: 'bg-red-500/10', count: 12 },
  { id: 2, name: 'Scarborough', status: 'Moderate', color: 'text-orange-500', bg: 'bg-orange-500/10', count: 5 },
  { id: 3, name: 'North York', status: 'Low Activity', color: 'text-green-500', bg: 'bg-green-500/10', count: 2 },
  { id: 4, name: 'Etobicoke', status: 'Normal', color: 'text-blue-500', bg: 'bg-blue-500/10', count: 1 },
  { id: 5, name: 'Peel Region', status: 'Monitoring', color: 'text-gray-400', bg: 'bg-white/5', count: 0 },
  { id: 6, name: 'York Region', status: 'Monitoring', color: 'text-gray-400', bg: 'bg-white/5', count: 0 },
];

export const ZonesView: React.FC = () => {
  return (
    <div className="p-4 md:p-6">
      <div className="mb-6 flex justify-between items-end">
        <div>
            <h2 className="text-2xl font-bold text-white mb-2 flex items-center gap-3">
            <Icon name="map" className="text-primary" />
            Active Zones
            </h2>
            <p className="text-text-secondary text-sm">Real-time status by geographic region.</p>
        </div>
        <button className="hidden md:flex items-center gap-2 text-primary hover:text-white transition-colors text-sm font-medium">
            <Icon name="add_location_alt" />
            Manage Zones
        </button>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        {ZONES.map((zone) => (
          <div key={zone.id} className="bg-surface-dark border border-white/5 rounded-xl p-5 hover:border-white/20 transition-all cursor-pointer group">
            <div className="flex justify-between items-start mb-4">
              <div className={`w-10 h-10 rounded-lg flex items-center justify-center ${zone.bg} ${zone.color}`}>
                <Icon name="location_on" />
              </div>
              <span className="text-2xl font-bold text-white">{zone.count}</span>
            </div>
            
            <h3 className="text-white font-bold text-lg mb-1 group-hover:text-primary transition-colors">{zone.name}</h3>
            
            <div className="flex items-center gap-2">
                <span className={`w-2 h-2 rounded-full ${zone.color === 'text-gray-400' ? 'bg-gray-500' : zone.color.replace('text-', 'bg-')}`}></span>
                <span className={`text-xs font-medium ${zone.color}`}>{zone.status}</span>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
};