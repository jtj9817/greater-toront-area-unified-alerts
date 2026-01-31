
import React from 'react';
import { Icon } from './Icon';

interface SidebarProps {
  currentView: string;
  onNavigate: (view: string) => void;
  isCollapsed: boolean;
  onToggleCollapse: () => void;
  isMobileOpen: boolean;
  onCloseMobile: () => void;
}

export const Sidebar: React.FC<SidebarProps> = ({ 
  currentView, 
  onNavigate, 
  isCollapsed, 
  onToggleCollapse, 
  isMobileOpen, 
  onCloseMobile 
}) => {
  const navItems = [
    { id: 'feed', name: 'Feed', icon: 'grid_view', badge: '6' },
    { id: 'saved', name: 'Saved', icon: 'bookmark' },
    { id: 'zones', name: 'Zones', icon: 'map' },
    { id: 'settings', name: 'Settings', icon: 'settings' },
  ];

  const sidebarWidth = isCollapsed ? 'md:w-20' : 'md:w-64';
  const mobileTranslate = isMobileOpen ? 'translate-x-0' : '-translate-x-full';

  return (
    <aside className={`
      fixed inset-y-0 left-0 z-[100] w-[75%] max-w-[280px] md:relative md:translate-x-0
      bg-[#1a120b] border-r border-white/5 pt-6 pb-6 flex-none flex flex-col
      transition-all duration-300 ease-in-out shadow-2xl md:shadow-none
      ${mobileTranslate} ${sidebarWidth}
    `}>
      
      {/* Mobile Close Button */}
      <button 
        onClick={onCloseMobile}
        className="md:hidden absolute top-4 right-4 text-text-secondary hover:text-white transition-colors"
      >
        <Icon name="close" />
      </button>

      {/* Logo Section */}
      <div className={`px-6 mb-8 flex items-center gap-3 overflow-hidden ${isCollapsed ? 'md:px-4 justify-center' : ''}`}>
        <div className="flex-none w-8 h-8 rounded-lg bg-gradient-to-br from-primary to-orange-600 flex items-center justify-center text-white shadow-lg shadow-primary/20">
          <Icon name="emergency_home" className="text-xl" />
        </div>
        {!isCollapsed && (
          <div className="whitespace-nowrap animate-in fade-in slide-in-from-left-2 duration-300">
            <h1 className="text-white text-lg font-bold tracking-tight leading-none">GTA Alerts</h1>
            <p className="text-text-secondary text-[10px] mt-1 font-medium tracking-wider uppercase">Dashboard Pro</p>
          </div>
        )}
      </div>

      {/* Navigation */}
      <nav className="flex-1 px-3 space-y-1 overflow-y-auto no-scrollbar">
        {!isCollapsed && (
          <p className="px-3 text-[10px] font-bold text-text-secondary/50 uppercase tracking-widest mb-2 mt-4 whitespace-nowrap overflow-hidden">Menu</p>
        )}
        
        {navItems.map((item) => (
          <button
            key={item.id}
            onClick={() => onNavigate(item.id)}
            className={`w-full flex items-center gap-3 px-3 py-2.5 rounded-lg transition-all group relative ${
              currentView === item.id 
                ? 'bg-primary text-white shadow-lg shadow-primary/20' 
                : 'text-gray-400 hover:bg-white/5 hover:text-white'
            } ${isCollapsed ? 'md:justify-center' : ''}`}
            title={isCollapsed ? item.name : ''}
          >
            <Icon 
              name={item.icon} 
              className={currentView === item.id ? 'fill-current' : ''} 
              fill={currentView === item.id} 
            />
            {!isCollapsed && (
              <span className="font-medium text-sm whitespace-nowrap animate-in fade-in duration-300">{item.name}</span>
            )}
            {!isCollapsed && item.badge && (
               <span className={`ml-auto text-[10px] font-bold px-2 py-0.5 rounded-full ${
                 currentView === item.id ? 'bg-white/20 text-white' : 'bg-surface-dark text-text-secondary group-hover:bg-white/10'
               }`}>
                 {item.badge}
               </span>
            )}
            {/* Tooltip for collapsed view (simplified) */}
            {isCollapsed && (
              <div className="absolute left-full ml-4 px-2 py-1 bg-primary text-white text-[10px] rounded opacity-0 pointer-events-none group-hover:opacity-100 transition-opacity whitespace-nowrap z-50">
                {item.name}
              </div>
            )}
          </button>
        ))}

        <div className="pt-4 mt-4 border-t border-white/5">
           {!isCollapsed && (
            <p className="px-3 text-[10px] font-bold text-text-secondary/50 uppercase tracking-widest mb-2 whitespace-nowrap overflow-hidden">System</p>
          )}
          <button className={`w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-gray-400 hover:bg-white/5 hover:text-white transition-all ${isCollapsed ? 'md:justify-center' : ''}`}>
            <Icon name="dns" />
            {!isCollapsed && <span className="font-medium text-sm flex-1 text-left">Status</span>}
            {!isCollapsed && <span className="w-2 h-2 rounded-full bg-green-500 shadow-[0_0_8px_rgba(34,197,94,0.6)]"></span>}
          </button>
        </div>
      </nav>

      {/* Profile & Collapse Toggle Section */}
      <div className="px-3 mt-auto space-y-3">
        {/* User Card */}
        <div className={`bg-surface-dark rounded-xl p-2.5 border border-white/5 flex items-center gap-3 hover:border-white/10 transition-colors cursor-pointer group ${isCollapsed ? 'md:justify-center' : ''}`}>
            <div className="flex-none w-9 h-9 rounded-full bg-primary/10 flex items-center justify-center text-primary border border-primary/20">
                <span className="font-bold text-sm">JD</span>
            </div>
            {!isCollapsed && (
              <div className="flex-1 min-w-0 overflow-hidden animate-in fade-in duration-300">
                  <p className="text-white text-sm font-medium truncate group-hover:text-primary transition-colors">John Doe</p>
                  <p className="text-text-secondary text-xs truncate">Premium</p>
              </div>
            )}
        </div>

        {/* Desktop Collapse Toggle */}
        <button 
          onClick={onToggleCollapse}
          className="hidden md:flex w-full items-center justify-center py-2.5 rounded-lg bg-white/5 text-text-secondary hover:text-white hover:bg-primary/20 transition-all border border-transparent hover:border-primary/30"
          title={isCollapsed ? "Expand Sidebar" : "Collapse Sidebar"}
        >
          <Icon name={isCollapsed ? "chevron_right" : "chevron_left"} />
        </button>
      </div>
    </aside>
  );
};
