import React from 'react';
import { Icon } from './Icon';

export const Footer: React.FC = () => {
    return (
        <footer
            id="gta-alerts-footer"
            className="hidden h-12 flex-none items-center justify-between border-t-4 border-primary bg-background-dark px-8 text-[11px] font-black tracking-widest text-white uppercase md:flex"
        >
            <div id="gta-alerts-footer-weather" className="flex gap-8">
                <span
                    id="gta-alerts-footer-weather-text"
                    className="flex items-center gap-2"
                >
                    <Icon name="thermostat" className="text-xs" />
                    Temp: 24 C | Humidity: 65% | Wind: 15km/h W
                </span>
            </div>
            <div id="gta-alerts-footer-links" className="flex gap-6">
                <a
                    id="gta-alerts-footer-link-archives"
                    className="border-b border-transparent transition-colors hover:border-primary hover:text-primary"
                    href="#"
                >
                    Incident Archives
                </a>
                <a
                    id="gta-alerts-footer-link-privacy"
                    className="border-b border-transparent transition-colors hover:border-primary hover:text-primary"
                    href="#"
                >
                    Privacy Policy
                </a>
                <a
                    id="gta-alerts-footer-link-status"
                    className="border-b border-transparent transition-colors hover:border-primary hover:text-primary"
                    href="#"
                >
                    System Status
                </a>
            </div>
        </footer>
    );
};
