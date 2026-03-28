import React, { useEffect, useRef, useState } from 'react';
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
// Detail panel rows — only non-null, non-empty values are shown
// ---------------------------------------------------------------------------

interface DetailRow {
    id: string;
    label: string;
    value: string;
}

function buildDetailRows(weather: WeatherData): DetailRow[] {
    const rows: DetailRow[] = [];

    const add = (id: string, label: string, value: string | null | undefined) => {
        if (value !== null && value !== undefined && value.trim() !== '') {
            rows.push({ id, label, value });
        }
    };

    add('feels-like', 'Feels Like', weather.feelsLike !== null ? `${weather.feelsLike} °C` : null);
    add('condition', 'Condition', weather.condition);
    add('dewpoint', 'Dewpoint', weather.dewpoint !== null ? `${weather.dewpoint} °C` : null);
    add('pressure', 'Pressure', weather.pressure !== null ? `${weather.pressure} kPa` : null);
    add('visibility', 'Visibility', weather.visibility !== null ? `${weather.visibility} km` : null);
    add('wind-gust', 'Wind Gust', weather.windGust);
    add('tendency', 'Tendency', weather.tendency ? weather.tendency.charAt(0).toUpperCase() + weather.tendency.slice(1) : null);
    add('station-name', 'Station', weather.stationName);

    return rows;
}

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
    const [isPanelOpen, setIsPanelOpen] = useState(false);
    const panelRef = useRef<HTMLDivElement>(null);
    const triggerRef = useRef<HTMLButtonElement>(null);

    // Close on click-outside and Escape
    useEffect(() => {
        if (!isPanelOpen) return;

        const handleClickOutside = (event: MouseEvent) => {
            if (
                panelRef.current &&
                !panelRef.current.contains(event.target as Node) &&
                triggerRef.current &&
                !triggerRef.current.contains(event.target as Node)
            ) {
                setIsPanelOpen(false);
            }
        };

        const handleEscape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setIsPanelOpen(false);
            }
        };

        document.addEventListener('mousedown', handleClickOutside);
        document.addEventListener('keydown', handleEscape);

        return () => {
            document.removeEventListener('mousedown', handleClickOutside);
            document.removeEventListener('keydown', handleEscape);
        };
    }, [isPanelOpen]);

    const detailRows = weather ? buildDetailRows(weather) : [];
    const canOpenPanel = weather !== null && detailRows.length > 0;

    return (
        <footer
            id="gta-alerts-footer"
            className="flex h-12 flex-none items-center justify-between border-t-4 border-primary bg-background-dark px-8 text-[11px] font-black tracking-widest text-white uppercase"
        >
            <div className="relative z-[95]">
                {/* Weather detail panel */}
                {isPanelOpen && weather && (
                    <div
                        ref={panelRef}
                        id="gta-alerts-footer-weather-panel"
                        className="absolute bottom-12 left-0 mb-2 w-64 animate-in overflow-hidden rounded-lg border-2 border-black bg-[#1a1a1a] shadow-[5px_5px_0_#000] duration-200 fade-in slide-in-from-bottom-2"
                    >
                        <div className="border-b border-[#333333] px-3 py-2">
                            <span className="text-[10px] font-bold tracking-widest text-text-secondary uppercase">
                                {weather.fsa} — Current Conditions
                            </span>
                        </div>
                        <div className="py-1">
                            {detailRows.map((row) => (
                                <div
                                    key={row.id}
                                    id={`gta-alerts-footer-weather-detail-${row.id}`}
                                    className="flex items-center justify-between px-3 py-1.5 text-xs"
                                >
                                    <span className="text-text-secondary font-medium normal-case tracking-normal">
                                        {row.label}
                                    </span>
                                    <span className="font-bold">{row.value}</span>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* Weather bar trigger */}
                <button
                    ref={triggerRef}
                    id="gta-alerts-footer-weather"
                    type="button"
                    disabled={!canOpenPanel}
                    onClick={() => setIsPanelOpen((prev) => !prev)}
                    aria-expanded={canOpenPanel ? isPanelOpen : undefined}
                    aria-haspopup={canOpenPanel ? true : undefined}
                    className={`flex gap-8 bg-transparent p-0 text-inherit ${canOpenPanel ? 'cursor-pointer' : 'cursor-default'}`}
                >
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
                                {weather.feelsLike !== null &&
                                    ` (Feels Like ${weather.feelsLike} °C)`}
                                {weather.humidity !== null &&
                                    ` | Humidity: ${weather.humidity}%`}
                                {weather.windSpeed !== null &&
                                    ` | Wind: ${weather.windSpeed}${weather.windDirection ? ` ${weather.windDirection}` : ''}`}
                                {canOpenPanel && (
                                    <Icon
                                        name={isPanelOpen ? 'expand_less' : 'expand_more'}
                                        className="text-xs opacity-60"
                                    />
                                )}
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
                </button>
            </div>

            <div id="gta-alerts-footer-links" className="hidden gap-6 md:flex">
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
