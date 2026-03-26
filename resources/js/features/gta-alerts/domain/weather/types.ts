/**
 * Domain representation of live weather conditions for a GTA postal area.
 * All fields are camelCase; null indicates data unavailable from provider.
 */
export type WeatherData = {
    fsa: string;
    provider: string;
    temperature: number | null;
    humidity: number | null;
    windSpeed: string | null;
    windDirection: string | null;
    condition: string | null;
    alertLevel: 'yellow' | 'orange' | 'red' | null;
    alertText: string | null;
    fetchedAt: string;
};

/**
 * A resolved GTA location (FSA + human-readable label + centroid coordinates).
 * Stored in localStorage when the user selects their location.
 */
export type WeatherLocation = {
    fsa: string;
    label: string;
    lat: number;
    lng: number;
};
