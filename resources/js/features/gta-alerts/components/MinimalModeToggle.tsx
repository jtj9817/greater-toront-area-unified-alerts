import React, { useEffect, useRef, useState } from 'react';
import { Icon } from './Icon';
import type { MinimalModeSection } from '../hooks/useMinimalMode';

interface MinimalModeToggleProps {
    isHidden: (section: MinimalModeSection) => boolean;
    toggleSection: (section: MinimalModeSection) => void;
    isMinimalMode: boolean;
    toggleMinimalMode: () => void;
}

interface MenuItem {
    id: MinimalModeSection | 'all';
    label: string;
    icon: string;
    isActive: () => boolean;
    onToggle: () => void;
}

export const MinimalModeToggle: React.FC<MinimalModeToggleProps> = ({
    isHidden,
    toggleSection,
    isMinimalMode,
    toggleMinimalMode,
}) => {
    const [isOpen, setIsOpen] = useState(false);
    const menuRef = useRef<HTMLDivElement>(null);
    const buttonRef = useRef<HTMLButtonElement>(null);

    // Close menu when clicking outside
    useEffect(() => {
        if (!isOpen) return;

        const handleClickOutside = (event: MouseEvent) => {
            if (
                menuRef.current &&
                !menuRef.current.contains(event.target as Node) &&
                buttonRef.current &&
                !buttonRef.current.contains(event.target as Node)
            ) {
                setIsOpen(false);
            }
        };

        const handleEscape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setIsOpen(false);
            }
        };

        document.addEventListener('mousedown', handleClickOutside);
        document.addEventListener('keydown', handleEscape);

        return () => {
            document.removeEventListener('mousedown', handleClickOutside);
            document.removeEventListener('keydown', handleEscape);
        };
    }, [isOpen]);

    const menuItems: MenuItem[] = [
        {
            id: 'status',
            label: 'Status Filter',
            icon: 'toggle_on',
            isActive: () => !isHidden('status'),
            onToggle: () => toggleSection('status'),
        },
        {
            id: 'category',
            label: 'Category Filter',
            icon: 'category',
            isActive: () => !isHidden('category'),
            onToggle: () => toggleSection('category'),
        },
        {
            id: 'filter',
            label: 'Time & Sort',
            icon: 'tune',
            isActive: () => !isHidden('filter'),
            onToggle: () => toggleSection('filter'),
        },
        {
            id: 'all',
            label: isMinimalMode ? 'Show All' : 'Minimal Mode',
            icon: isMinimalMode ? 'expand_more' : 'expand_less',
            isActive: () => isMinimalMode,
            onToggle: () => toggleMinimalMode(),
        },
    ];

    return (
        <div className="relative z-[95]">
            {/* Menu */}
            {isOpen && (
                <div
                    ref={menuRef}
                    id="gta-alerts-minimal-mode-menu"
                    className="absolute bottom-16 right-0 mb-2 w-48 overflow-hidden rounded-lg border-2 border-black bg-[#1a1a1a] shadow-[5px_5px_0_#000] animate-in slide-in-from-bottom-2 fade-in duration-200"
                >
                    <div className="border-b border-[#333333] px-3 py-2">
                        <span className="text-[10px] font-bold tracking-widest text-text-secondary uppercase">
                            View Options
                        </span>
                    </div>
                    <div className="py-1">
                        {menuItems.map((item) => (
                            <button
                                key={item.id}
                                id={`gta-alerts-minimal-mode-toggle-${item.id}`}
                                onClick={() => {
                                    item.onToggle();
                                    // Don't close menu on individual toggles
                                    // Only close on 'all' toggle
                                    if (item.id === 'all') {
                                        setIsOpen(false);
                                    }
                                }}
                                className={`flex w-full items-center gap-3 px-3 py-2.5 text-left text-xs font-medium transition-colors ${
                                    item.isActive()
                                        ? 'bg-[#FF7F00] text-black'
                                        : 'text-white hover:bg-[#333333]'
                                }`}
                            >
                                <Icon
                                    name={item.icon}
                                    className="text-base"
                                    fill={item.isActive()}
                                />
                                <span className="flex-1">{item.label}</span>
                                {item.id !== 'all' && (
                                    <span
                                        className={`h-2 w-2 rounded-full ${
                                            item.isActive()
                                                ? 'bg-black'
                                                : 'bg-[#333333]'
                                        }`}
                                    />
                                )}
                            </button>
                        ))}
                    </div>
                </div>
            )}

            {/* FAB Button */}
            <button
                ref={buttonRef}
                id="gta-alerts-minimal-mode-fab"
                onClick={() => setIsOpen(!isOpen)}
                aria-label="Toggle view options"
                aria-expanded={isOpen}
                className={`flex h-12 w-12 items-center justify-center border-2 border-black shadow-[5px_5px_0_#000] transition-all hover:translate-x-[1px] hover:translate-y-[1px] hover:shadow-none ${
                    isMinimalMode
                        ? 'bg-[#FF7F00] text-black'
                        : 'bg-primary text-black'
                } ${isOpen ? 'translate-x-[1px] translate-y-[1px] shadow-none' : ''}`}
            >
                <Icon
                    name={isOpen ? 'close' : isMinimalMode ? 'expand_more' : 'expand_less'}
                    className="text-xl"
                />
            </button>
        </div>
    );
};
