import { render, screen, fireEvent } from '@testing-library/react';
import React from 'react';
import { describe, it, expect } from 'vitest';
import type { WeatherData } from '../domain/weather/types';
import { Footer } from './Footer';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeWeatherData(overrides: Partial<WeatherData> = {}): WeatherData {
    return {
        fsa: 'M5V',
        provider: 'environment_canada',
        temperature: 15.5,
        humidity: 65,
        windSpeed: '20 km/h',
        windDirection: 'NW',
        condition: 'Mostly Cloudy',
        alertLevel: null,
        alertText: null,
        fetchedAt: '2026-03-25T12:00:00+00:00',
        feelsLike: null,
        dewpoint: null,
        pressure: null,
        visibility: null,
        windGust: null,
        tendency: null,
        stationName: null,
        ...overrides,
    };
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('Footer', () => {
    // -----------------------------------------------------------------------
    // No weather data
    // -----------------------------------------------------------------------

    it('renders without weather data (no location selected)', () => {
        render(<Footer weather={null} />);
        expect(screen.getByRole('contentinfo')).toBeInTheDocument();
    });

    it('shows a prompt to select a location when weather is null', () => {
        render(<Footer weather={null} />);
        expect(
            screen.getByText(/select.*location|choose.*location|no location/i),
        ).toBeInTheDocument();
    });

    // -----------------------------------------------------------------------
    // Current conditions display
    // -----------------------------------------------------------------------

    it('displays temperature when weather data is provided', () => {
        render(<Footer weather={makeWeatherData({ temperature: 15.5 })} />);
        expect(screen.getByText(/15\.5/)).toBeInTheDocument();
    });

    it('displays humidity when weather data is provided', () => {
        render(<Footer weather={makeWeatherData({ humidity: 65 })} />);
        expect(screen.getByText(/65/)).toBeInTheDocument();
    });

    it('displays wind speed when weather data is provided', () => {
        render(<Footer weather={makeWeatherData({ windSpeed: '20 km/h' })} />);
        expect(screen.getByText(/20 km\/h/)).toBeInTheDocument();
    });

    it('displays wind direction when weather data is provided', () => {
        render(<Footer weather={makeWeatherData({ windDirection: 'NW' })} />);
        expect(screen.getByText(/NW/)).toBeInTheDocument();
    });

    it('renders gracefully when temperature is null', () => {
        render(<Footer weather={makeWeatherData({ temperature: null })} />);
        // Should render without throwing
        expect(screen.getByRole('contentinfo')).toBeInTheDocument();
    });

    // -----------------------------------------------------------------------
    // Alert badges
    // -----------------------------------------------------------------------

    it('does not show an alert badge when alertLevel is null', () => {
        render(<Footer weather={makeWeatherData({ alertLevel: null })} />);
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    it('shows a yellow alert badge when alertLevel is "yellow"', () => {
        render(
            <Footer
                weather={makeWeatherData({
                    alertLevel: 'yellow',
                    alertText: 'Special weather statement.',
                })}
            />,
        );
        const badge = screen.getByRole('status');
        expect(badge).toBeInTheDocument();
        expect(badge.textContent).toMatch(/yellow|statement|warning/i);
    });

    it('shows an orange alert badge when alertLevel is "orange"', () => {
        render(
            <Footer
                weather={makeWeatherData({
                    alertLevel: 'orange',
                    alertText: 'Freezing rain warning.',
                })}
            />,
        );
        const badge = screen.getByRole('status');
        expect(badge).toBeInTheDocument();
    });

    it('shows a red alert badge when alertLevel is "red"', () => {
        render(
            <Footer
                weather={makeWeatherData({
                    alertLevel: 'red',
                    alertText: 'Tornado warning.',
                })}
            />,
        );
        const badge = screen.getByRole('status');
        expect(badge).toBeInTheDocument();
    });

    it('includes alertText in the alert badge content', () => {
        render(
            <Footer
                weather={makeWeatherData({
                    alertLevel: 'orange',
                    alertText: 'Freezing rain warning in effect.',
                })}
            />,
        );
        expect(screen.getByText(/Freezing rain warning/i)).toBeInTheDocument();
    });

    // -----------------------------------------------------------------------
    // Feels Like inline display
    // -----------------------------------------------------------------------

    it('displays Feels Like inline when feelsLike is present', () => {
        render(
            <Footer
                weather={makeWeatherData({ temperature: 1, feelsLike: -5.5 })}
            />,
        );
        expect(screen.getByText(/feels like/i)).toBeInTheDocument();
        expect(screen.getByText(/-5\.5/)).toBeInTheDocument();
    });

    it('does not show Feels Like text when feelsLike is null', () => {
        render(<Footer weather={makeWeatherData({ feelsLike: null })} />);
        expect(screen.queryByText(/feels like/i)).not.toBeInTheDocument();
    });

    // -----------------------------------------------------------------------
    // Weather detail panel
    // -----------------------------------------------------------------------

    it('opens the detail panel when weather bar is clicked', () => {
        render(
            <Footer
                weather={makeWeatherData({
                    feelsLike: -5.5,
                    condition: 'Mostly Cloudy',
                    dewpoint: -10.0,
                })}
            />,
        );
        expect(
            document.getElementById('gta-alerts-footer-weather-panel'),
        ).not.toBeInTheDocument();

        fireEvent.click(document.getElementById('gta-alerts-footer-weather')!);

        expect(
            document.getElementById('gta-alerts-footer-weather-panel'),
        ).toBeInTheDocument();
    });

    it('resets open state when weather becomes unavailable, preventing auto-reopen', () => {
        const { rerender } = render(
            <Footer
                weather={makeWeatherData({
                    feelsLike: -5.5,
                })}
            />,
        );

        fireEvent.click(document.getElementById('gta-alerts-footer-weather')!);
        expect(
            document.getElementById('gta-alerts-footer-weather-panel'),
        ).toBeInTheDocument();

        rerender(<Footer weather={null} />);
        expect(
            document.getElementById('gta-alerts-footer-weather-panel'),
        ).not.toBeInTheDocument();

        rerender(
            <Footer
                weather={makeWeatherData({
                    feelsLike: -5.5,
                    fetchedAt: '2026-03-25T12:05:00+00:00',
                })}
            />,
        );
        expect(
            document.getElementById('gta-alerts-footer-weather-panel'),
        ).not.toBeInTheDocument();
    });

    it('shows non-null detail rows in the panel', () => {
        render(
            <Footer
                weather={makeWeatherData({
                    feelsLike: -5.5,
                    condition: 'Mostly Cloudy',
                    dewpoint: -10.2,
                    pressure: 103.2,
                    stationName: "Toronto Pearson Int'l Airport",
                })}
            />,
        );

        fireEvent.click(document.getElementById('gta-alerts-footer-weather')!);

        expect(
            document.getElementById(
                'gta-alerts-footer-weather-detail-feels-like',
            ),
        ).toBeInTheDocument();
        expect(
            document.getElementById(
                'gta-alerts-footer-weather-detail-condition',
            ),
        ).toBeInTheDocument();
        expect(
            document.getElementById(
                'gta-alerts-footer-weather-detail-dewpoint',
            ),
        ).toBeInTheDocument();
        expect(
            document.getElementById(
                'gta-alerts-footer-weather-detail-pressure',
            ),
        ).toBeInTheDocument();
        expect(
            document.getElementById(
                'gta-alerts-footer-weather-detail-station-name',
            ),
        ).toBeInTheDocument();
    });

    it('omits null fields from the detail panel', () => {
        render(
            <Footer
                weather={makeWeatherData({
                    feelsLike: -5.5,
                    pressure: null,
                    visibility: null,
                    windGust: null,
                })}
            />,
        );

        fireEvent.click(document.getElementById('gta-alerts-footer-weather')!);

        expect(
            document.getElementById(
                'gta-alerts-footer-weather-detail-pressure',
            ),
        ).not.toBeInTheDocument();
        expect(
            document.getElementById(
                'gta-alerts-footer-weather-detail-visibility',
            ),
        ).not.toBeInTheDocument();
        expect(
            document.getElementById(
                'gta-alerts-footer-weather-detail-wind-gust',
            ),
        ).not.toBeInTheDocument();
    });

    it('closes the panel when Escape is pressed', () => {
        render(<Footer weather={makeWeatherData({ feelsLike: -5.5 })} />);

        fireEvent.click(document.getElementById('gta-alerts-footer-weather')!);
        expect(
            document.getElementById('gta-alerts-footer-weather-panel'),
        ).toBeInTheDocument();

        fireEvent.keyDown(document, { key: 'Escape' });
        expect(
            document.getElementById('gta-alerts-footer-weather-panel'),
        ).not.toBeInTheDocument();
    });

    it('does not open the panel when weather is null', () => {
        render(<Footer weather={null} />);
        const weatherBar = document.getElementById('gta-alerts-footer-weather');
        if (weatherBar) {
            fireEvent.click(weatherBar);
        }
        expect(
            document.getElementById('gta-alerts-footer-weather-panel'),
        ).not.toBeInTheDocument();
    });

    // -----------------------------------------------------------------------
    // Static footer links
    // -----------------------------------------------------------------------

    it('renders the footer navigation links', () => {
        render(<Footer weather={null} />);
        expect(screen.getByText(/Incident Archives/i)).toBeInTheDocument();
        expect(screen.getByText(/Privacy Policy/i)).toBeInTheDocument();
        expect(screen.getByText(/System Status/i)).toBeInTheDocument();
    });
});
