import React from 'react';
import { Icon } from './Icon';

export const Footer: React.FC = () => {
    return (
        <footer className="hidden h-12 flex-none items-center justify-between border-t-4 border-primary bg-background-dark px-8 text-[11px] font-black tracking-widest text-white uppercase md:flex">
            <div className="flex gap-8">
                <span className="flex items-center gap-2">
                    <Icon name="thermostat" className="text-xs" />
                    Temp: 24 C | Humidity: 65% | Wind: 15km/h W
                </span>
            </div>
            <div className="flex gap-6">
                <a
                    className="border-b border-transparent transition-colors hover:border-primary hover:text-primary"
                    href="#"
                >
                    Incident Archives
                </a>
                <a
                    className="border-b border-transparent transition-colors hover:border-primary hover:text-primary"
                    href="#"
                >
                    Privacy Policy
                </a>
                <a
                    className="border-b border-transparent transition-colors hover:border-primary hover:text-primary"
                    href="#"
                >
                    System Status
                </a>
            </div>
        </footer>
    );
};
