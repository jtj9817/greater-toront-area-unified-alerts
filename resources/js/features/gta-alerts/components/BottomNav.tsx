import React from 'react';
import { Icon } from './Icon';

interface BottomNavProps {
    currentView: string;
    onNavigate: (view: string) => void;
}

export const BottomNav: React.FC<BottomNavProps> = ({
    currentView,
    onNavigate,
}) => {
    const navItems = [
        { id: 'feed', name: 'Feed', icon: 'grid_view' },
        { id: 'inbox', name: 'Inbox', icon: 'notifications' },
        { id: 'saved', name: 'Saved', icon: 'bookmark' },
        { id: 'zones', name: 'Zones', icon: 'map' },
        { id: 'settings', name: 'Settings', icon: 'settings' },
    ];

    return (
        <nav
            id="gta-alerts-bottom-nav"
            className="z-50 flex-none border-t border-white/10 bg-background-dark px-6 pt-3 pb-6 md:hidden"
        >
            <ul
                id="gta-alerts-bottom-nav-list"
                className="flex items-center justify-between"
            >
                {navItems.map((item) => (
                    <li
                        id={`gta-alerts-bottom-nav-item-${item.id}`}
                        key={item.id}
                    >
                        <button
                            id={`gta-alerts-bottom-nav-btn-${item.id}`}
                            onClick={() => onNavigate(item.id)}
                            className={`flex flex-col items-center gap-1 transition-colors ${
                                currentView === item.id
                                    ? 'text-primary'
                                    : 'text-text-secondary hover:text-white'
                            }`}
                        >
                            <Icon
                                name={item.icon}
                                className={
                                    currentView === item.id
                                        ? 'fill-current'
                                        : ''
                                }
                                fill={currentView === item.id}
                            />
                            <span className="text-[10px] font-medium">
                                {item.name}
                            </span>
                        </button>
                    </li>
                ))}
            </ul>
        </nav>
    );
};
