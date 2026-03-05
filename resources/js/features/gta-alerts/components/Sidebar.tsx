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
        { id: 'feed', name: 'Feed', icon: 'feed' },
        { id: 'inbox', name: 'Inbox', icon: 'notifications' },
        { id: 'saved', name: 'Saved', icon: 'bookmark' },
        { id: 'zones', name: 'Zones', icon: 'map' },
        { id: 'settings', name: 'Settings', icon: 'settings' },
    ];

    const sidebarWidth = isCollapsed ? 'md:w-20' : 'md:w-64';
    const mobileTranslate = isMobileOpen
        ? 'translate-x-0 pointer-events-auto'
        : '-translate-x-full pointer-events-none';

    return (
        <aside
            id="gta-alerts-sidebar"
            className={`fixed inset-y-0 left-0 z-[100] flex w-[75%] max-w-[280px] flex-none flex-col border-r border-[#333333] bg-black pt-4 pb-4 shadow-2xl transition-all duration-300 ease-in-out md:pointer-events-auto md:relative md:translate-x-0 md:shadow-none ${mobileTranslate} ${sidebarWidth} `}
        >
            <button
                onClick={onCloseMobile}
                className="absolute top-4 right-4 border border-[#333333] bg-[#1a1a1a] p-1 text-white transition-colors hover:bg-primary hover:text-black md:hidden"
                aria-label="Close menu"
            >
                <Icon name="close" />
            </button>

            <div
                className={`flex items-center gap-3 overflow-hidden border-b border-[#333333] px-4 py-4 md:px-6 ${isCollapsed ? 'justify-center md:px-4' : ''}`}
            >
                <div className="bg-primary p-2 text-black">
                    <Icon
                        name="local_fire_department"
                        className="block text-2xl"
                        fill
                    />
                </div>
                {!isCollapsed && (
                    <h1 className="text-xl leading-none font-black tracking-tighter whitespace-nowrap text-white uppercase">
                        GTA Alerts
                    </h1>
                )}
            </div>

            <nav className="no-scrollbar flex-1 space-y-2 overflow-y-auto p-3 md:p-4">
                {navItems.map((item) => (
                    <button
                        key={item.id}
                        onClick={() => onNavigate(item.id)}
                        className={`group relative flex w-full items-center gap-3 px-3 py-3 text-xs font-black tracking-wide uppercase transition-colors ${
                            currentView === item.id
                                ? 'bg-primary text-black'
                                : 'text-white hover:bg-[#333333]'
                        } ${isCollapsed ? 'md:justify-center' : ''}`}
                        title={isCollapsed ? item.name : ''}
                    >
                        <Icon
                            name={item.icon}
                            className="text-[22px]"
                            fill={currentView === item.id}
                        />
                        {!isCollapsed && (
                            <span className="animate-in whitespace-nowrap duration-300 fade-in">
                                {item.name}
                            </span>
                        )}
                        {isCollapsed && (
                            <div className="pointer-events-none absolute left-full z-50 ml-4 border border-black bg-primary px-2 py-1 text-[10px] whitespace-nowrap text-black opacity-0 transition-opacity group-hover:opacity-100">
                                {item.name}
                            </div>
                        )}
                    </button>
                ))}
            </nav>

            <div className="mt-auto border-t border-[#333333] px-3 pt-3 md:px-4">
                <button
                    onClick={onToggleCollapse}
                    className={`hidden w-full items-center justify-center border border-[#333333] bg-[#1a1a1a] py-2.5 text-white transition-colors hover:bg-primary hover:text-black md:flex ${isCollapsed ? '' : 'gap-2'}`}
                    title={isCollapsed ? 'Expand Sidebar' : 'Collapse Sidebar'}
                >
                    <Icon
                        name={isCollapsed ? 'chevron_right' : 'chevron_left'}
                    />
                    {!isCollapsed && (
                        <span className="text-[10px] font-black tracking-widest uppercase">
                            Collapse
                        </span>
                    )}
                </button>
            </div>
        </aside>
    );
};
