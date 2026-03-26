import React from 'react';
import type { WeatherData } from '../domain/weather/types';
import { Icon } from './Icon';

// ---------------------------------------------------------------------------
// Alert badge colours
// ---------------------------------------------------------------------------

const ALERT_COLOURS: Record<NonNullable<WeatherData['alertLevel']>, string> = {
    yellow: 'bg-yellow-400 text-black',
    orange: 'bg-orange-500 text-white',
    red: 'bg-red-600 text-white',
};

// ---------------------------------------------------------------------------
// Props
// ---------------------------------------------------------------------------

interface FooterProps {
    /** Live weather data for the selected location, or null if none chosen. */
    weather: WeatherData | null;
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export const Footer: React.FC<FooterProps> = ({ weather }) => {
    return (
        <footer
            id="gta-alerts-footer"
            className="hidden h-12 flex-none items-center justify-between border-t-4 border-primary bg-background-dark px-8 text-[11px] font-black tracking-widest text-white uppercase md:flex"
        >
            <div id="gta-alerts-footer-weather" className="flex gap-8">
                {weather ? (
                    <>
                        <span
                            id="gta-alerts-footer-weather-text"
                            className="flex items-center gap-2"
                        >
                            <Icon name="thermostat" className="text-xs" />
                            {weather.temperature !== null
                                ? `${weather.temperature} °C`
                                : '— °C'}
                            {weather.humidity !== null &&
                                ` | Humidity: ${weather.humidity}%`}
                            {weather.windSpeed !== null &&
                                ` | Wind: ${weather.windSpeed}${weather.windDirection ? ` ${weather.windDirection}` : ''}`}
                        </span>

                        {weather.alertLevel && (
                            <span
                                role="status"
                                id="gta-alerts-footer-weather-alert"
                                className={`flex items-center gap-1 px-2 py-0.5 text-[10px] font-black uppercase ${ALERT_COLOURS[weather.alertLevel]}`}
                            >
                                <Icon name="warning" className="text-xs" />
                                {weather.alertText ??
                                    `${weather.alertLevel.charAt(0).toUpperCase() + weather.alertLevel.slice(1)} alert`}
                            </span>
                        )}
                    </>
                ) : (
                    <span
                        id="gta-alerts-footer-weather-no-location"
                        className="flex items-center gap-2 opacity-50"
                    >
                        <Icon name="location_off" className="text-xs" />
                        No location selected
                    </span>
                )}
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
