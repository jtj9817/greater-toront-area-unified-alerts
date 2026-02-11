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
    onCloseMobile,
}) => {
    const navItems = [
        { id: 'feed', name: 'Feed', icon: 'grid_view', badge: '6' },
        { id: 'inbox', name: 'Inbox', icon: 'notifications' },
        { id: 'saved', name: 'Saved', icon: 'bookmark' },
        { id: 'zones', name: 'Zones', icon: 'map' },
        { id: 'settings', name: 'Settings', icon: 'settings' },
    ];

    const sidebarWidth = isCollapsed ? 'md:w-20' : 'md:w-64';
    const mobileTranslate = isMobileOpen
        ? 'translate-x-0'
        : '-translate-x-full';

    return (
        <aside
            className={`fixed inset-y-0 left-0 z-[100] flex w-[75%] max-w-[280px] flex-none flex-col border-r border-white/5 bg-[#0c1117] pt-6 pb-6 shadow-2xl transition-all duration-300 ease-in-out md:relative md:translate-x-0 md:shadow-none ${mobileTranslate} ${sidebarWidth} `}
        >
            {/* Mobile Close Button */}
            <button
                onClick={onCloseMobile}
                className="absolute top-4 right-4 text-text-secondary transition-colors hover:text-white md:hidden"
            >
                <Icon name="close" />
            </button>

            {/* Logo Section */}
            <div
                className={`mb-8 flex items-center gap-3 overflow-hidden px-6 ${isCollapsed ? 'justify-center md:px-4' : ''}`}
            >
                <div className="flex h-8 w-8 flex-none items-center justify-center rounded-lg bg-gradient-to-br from-[#3b82f6] to-[#60a5fa] text-white shadow-lg shadow-primary/20">
                    <Icon name="emergency_home" className="text-xl" />
                </div>
                {!isCollapsed && (
                    <div className="animate-in whitespace-nowrap duration-300 fade-in slide-in-from-left-2">
                        <h1 className="text-lg leading-none font-bold tracking-tight text-white">
                            GTA Alerts
                        </h1>
                        <p className="mt-1 text-[10px] font-medium tracking-wider text-text-secondary uppercase">
                            Dashboard Pro
                        </p>
                    </div>
                )}
            </div>

            {/* Navigation */}
            <nav className="no-scrollbar flex-1 space-y-1 overflow-y-auto px-3">
                {!isCollapsed && (
                    <p className="mt-4 mb-2 overflow-hidden px-3 text-[10px] font-bold tracking-widest whitespace-nowrap text-text-secondary/50 uppercase">
                        Menu
                    </p>
                )}

                {navItems.map((item) => (
                    <button
                        key={item.id}
                        onClick={() => onNavigate(item.id)}
                        className={`group relative flex w-full items-center gap-3 rounded-lg px-3 py-2.5 transition-all ${
                            currentView === item.id
                                ? 'text-white shadow-lg'
                                : 'text-gray-400 hover:bg-white/5 hover:text-white'
                        } ${isCollapsed ? 'md:justify-center' : ''}`}
                        title={isCollapsed ? item.name : ''}
                    >
                        <Icon
                            name={item.icon}
                            className={
                                currentView === item.id ? 'fill-current' : ''
                            }
                            fill={currentView === item.id}
                        />
                        {!isCollapsed && (
                            <span className="animate-in text-sm font-medium whitespace-nowrap duration-300 fade-in">
                                {item.name}
                            </span>
                        )}
                        {!isCollapsed && item.badge && (
                            <span
                                className={`ml-auto rounded-full px-2 py-0.5 text-[10px] font-bold ${
                                    currentView === item.id
                                        ? 'bg-white/20 text-white'
                                        : 'bg-surface-dark text-text-secondary group-hover:bg-white/10'
                                }`}
                            >
                                {item.badge}
                            </span>
                        )}
                        {/* Tooltip for collapsed view (simplified) */}
                        {isCollapsed && (
                            <div className="pointer-events-none absolute left-full z-50 ml-4 rounded bg-primary px-2 py-1 text-[10px] whitespace-nowrap text-white opacity-0 transition-opacity group-hover:opacity-100">
                                {item.name}
                            </div>
                        )}
                    </button>
                ))}
            </nav>

            {/* Profile & Collapse Toggle Section */}
            <div className="mt-auto space-y-3 px-3">
                {/* User Card */}
                <div
                    className={`group flex cursor-pointer items-center gap-3 rounded-xl border border-white/5 bg-surface-dark p-2.5 transition-colors hover:border-white/10 ${isCollapsed ? 'md:justify-center' : ''}`}
                >
                    <div className="flex h-9 w-9 flex-none items-center justify-center rounded-full border border-primary/20 bg-primary/10 text-primary">
                        <span className="text-sm font-bold">JD</span>
                    </div>
                    {!isCollapsed && (
                        <div className="min-w-0 flex-1 animate-in overflow-hidden duration-300 fade-in">
                            <p className="truncate text-sm font-medium text-white transition-colors group-hover:text-primary">
                                John Doe
                            </p>
                            <p className="truncate text-xs text-text-secondary">
                                Premium
                            </p>
                        </div>
                    )}
                </div>

                {/* Desktop Collapse Toggle */}
                <button
                    onClick={onToggleCollapse}
                    className="hidden w-full items-center justify-center rounded-lg border border-transparent bg-white/5 py-2.5 text-text-secondary transition-all hover:border-white/20 hover:bg-white/10 hover:text-white md:flex"
                    title={isCollapsed ? 'Expand Sidebar' : 'Collapse Sidebar'}
                >
                    <Icon
                        name={isCollapsed ? 'chevron_right' : 'chevron_left'}
                    />
                </button>
            </div>
        </aside>
    );
};
